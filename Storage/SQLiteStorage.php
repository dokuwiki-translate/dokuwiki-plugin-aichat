<?php /** @noinspection SqlResolve */

namespace dokuwiki\plugin\aichat\Storage;

use dokuwiki\plugin\aichat\Chunk;
use dokuwiki\plugin\sqlite\SQLiteDB;
use KMeans\Cluster;
use KMeans\Space;

/**
 * Implements the storage backend using a SQLite database
 *
 * Note: all embeddings are stored and returned as normalized vectors
 */
class SQLiteStorage extends AbstractStorage
{
    /** @var float minimum similarity to consider a chunk a match */
    const SIMILARITY_THRESHOLD = 0.75;

    /** @var int Number of documents to randomly sample to create the clusters */
    const SAMPLE_SIZE = 2000;
    /** @var int The average size of each cluster */
    const CLUSTER_SIZE = 100;

    /** @var SQLiteDB */
    protected $db;

    /**
     * Initializes the database connection and registers our custom function
     *
     * @throws \Exception
     */
    public function __construct()
    {
        $this->db = new SQLiteDB('aichat', DOKU_PLUGIN . 'aichat/db/');
        $this->db->getPdo()->sqliteCreateFunction('COSIM', [$this, 'sqliteCosineSimilarityCallback'], 2);
    }

    /** @inheritdoc */
    public function getChunk($chunkID)
    {
        $record = $this->db->queryRecord('SELECT * FROM embeddings WHERE id = ?', [$chunkID]);
        if (!$record) return null;

        return new Chunk(
            $record['page'],
            $record['id'],
            $record['chunk'],
            json_decode($record['embedding'], true),
            $record['created']
        );
    }

    /** @inheritdoc */
    public function startCreation($clear = false)
    {
        if ($clear) {
            /** @noinspection SqlWithoutWhere */
            $this->db->exec('DELETE FROM embeddings');
        }
    }

    /** @inheritdoc */
    public function reusePageChunks($page, $firstChunkID)
    {
        // no-op
    }

    /** @inheritdoc */
    public function deletePageChunks($page, $firstChunkID)
    {
        $this->db->exec('DELETE FROM embeddings WHERE page = ?', [$page]);
    }

    /** @inheritdoc */
    public function addPageChunks($chunks)
    {
        foreach ($chunks as $chunk) {
            $this->db->saveRecord('embeddings', [
                'page' => $chunk->getPage(),
                'id' => $chunk->getId(),
                'chunk' => $chunk->getText(),
                'embedding' => json_encode($chunk->getEmbedding()),
                'created' => $chunk->getCreated()
            ]);
        }
    }

    /** @inheritdoc */
    public function finalizeCreation()
    {
        if (!$this->hasClusters()) {
            $this->createClusters();
        }
        $this->setChunkClusters();

        $this->db->exec('VACUUM');
    }

    /** @inheritdoc */
    public function runMaintenance()
    {
        $this->createClusters();
        $this->setChunkClusters();
    }

    /** @inheritdoc */
    public function getPageChunks($page, $firstChunkID)
    {
        $result = $this->db->queryAll(
            'SELECT * FROM embeddings WHERE page = ?',
            [$page]
        );
        $chunks = [];
        foreach ($result as $record) {
            $chunks[] = new Chunk(
                $record['page'],
                $record['id'],
                $record['chunk'],
                json_decode($record['embedding'], true),
                $record['created']
            );
        }
        return $chunks;
    }

    /** @inheritdoc */
    public function getSimilarChunks($vector, $limit = 4)
    {
        $clusters = $this->getClusters($vector, 4);
        $clusters = join(',', $clusters);
        if ($this->logger) $this->logger->info(
            'Using cluster {cluster} for similarity search', ['cluster' => $clusters]
        );

        $result = $this->db->queryAll(
            "SELECT *, COSIM(?, embedding) AS similarity
               FROM embeddings
              WHERE cluster IN ($clusters)
                AND GETACCESSLEVEL(page) > 0
                AND similarity > CAST(? AS FLOAT)
           ORDER BY similarity DESC
              LIMIT ?",
            [json_encode($vector), self::SIMILARITY_THRESHOLD, $limit]
        );
        $chunks = [];
        foreach ($result as $record) {
            $chunks[] = new Chunk(
                $record['page'],
                $record['id'],
                $record['chunk'],
                json_decode($record['embedding'], true),
                $record['created'],
                $record['similarity']
            );
        }
        return $chunks;
    }

    /** @inheritdoc */
    public function statistics()
    {
        $items = $this->db->queryValue('SELECT COUNT(*) FROM embeddings');
        $size = $this->db->queryValue(
            'SELECT page_count * page_size as size FROM pragma_page_count(), pragma_page_size()'
        );
        $query = "SELECT cluster, COUNT(*) || ' chunks' as cnt FROM embeddings GROUP BY cluster ORDER BY cluster";
        $clusters = $this->db->queryKeyValueList($query);

        return [
            'storage type' => 'SQLite',
            'chunks' => $items,
            'db size' => filesize_h($size),
            'clusters' => $clusters,
        ];
    }

    /**
     * Method registered as SQLite callback to calculate the cosine similarity
     *
     * @param string $query JSON encoded vector array
     * @param string $embedding JSON encoded vector array
     * @return float
     */
    public function sqliteCosineSimilarityCallback($query, $embedding)
    {
        return (float)$this->cosineSimilarity(json_decode($query), json_decode($embedding));
    }

    /**
     * Calculate the cosine similarity between two vectors
     *
     * Actually just calculating the dot product of the two vectors, since they are normalized
     *
     * @param float[] $queryVector The normalized vector of the search phrase
     * @param float[] $embedding The normalized vector of the chunk
     * @return float
     */
    protected function cosineSimilarity($queryVector, $embedding)
    {
        $dotProduct = 0;
        foreach ($queryVector as $key => $value) {
            $dotProduct += $value * $embedding[$key];
        }
        return $dotProduct;
    }

    /**
     * Create new clusters based on random chunks
     *
     * @noinspection SqlWithoutWhere
     */
    protected function createClusters()
    {
        if ($this->logger) $this->logger->info('Creating new clusters...');
        $this->db->getPdo()->beginTransaction();
        try {
            // clean up old cluster data
            $query = 'DELETE FROM clusters';
            $this->db->exec($query);
            $query = 'UPDATE embeddings SET cluster = NULL';
            $this->db->exec($query);

            // get a random selection of chunks
            $query = 'SELECT id, embedding FROM embeddings ORDER BY RANDOM() LIMIT ?';
            $result = $this->db->queryAll($query, [self::SAMPLE_SIZE]);
            if (!$result) return; // no data to cluster
            $dimensions = count(json_decode($result[0]['embedding'], true));

            // get the number of all chunks, to calculate the number of clusters
            $query = 'SELECT COUNT(*) FROM embeddings';
            $total = $this->db->queryValue($query);
            $clustercount = ceil($total / self::CLUSTER_SIZE);
            if ($this->logger) $this->logger->info('Creating {clusters} clusters', ['clusters' => $clustercount]);

            // cluster them using kmeans
            $space = new Space($dimensions);
            foreach ($result as $record) {
                $space->addPoint(json_decode($record['embedding'], true));
            }
            $clusters = $space->solve($clustercount, function ($space, $clusters) {
                static $iterations = 0;
                ++$iterations;
                if ($this->logger) {
                    $clustercounts = join(',', array_map('count', $clusters));
                    $this->logger->info('Iteration {iteration}: [{clusters}]', [
                        'iteration' => $iterations, 'clusters' => $clustercounts
                    ]);
                }
            }, Cluster::INIT_KMEANS_PLUS_PLUS);

            // store the clusters
            foreach ($clusters as $clusterID => $cluster) {
                /** @var Cluster $cluster */
                $centroid = $cluster->getCoordinates();
                $query = 'INSERT INTO clusters (cluster, centroid) VALUES (?, ?)';
                $this->db->exec($query, [$clusterID, json_encode($centroid)]);
            }

            $this->db->getPdo()->commit();
            if ($this->logger) $this->logger->success('Created {clusters} clusters', ['clusters' => count($clusters)]);
        } catch (\Exception $e) {
            $this->db->getPdo()->rollBack();
            throw new \RuntimeException('Clustering failed', 0, $e);
        }
    }

    /**
     * Assign the nearest cluster for all chunks that don't have one
     *
     * @return void
     */
    protected function setChunkClusters()
    {
        if ($this->logger) $this->logger->info('Assigning clusters to chunks...');
        $query = 'SELECT id, embedding FROM embeddings WHERE cluster IS NULL';
        $handle = $this->db->query($query);

        while ($record = $handle->fetch(\PDO::FETCH_ASSOC)) {
            $vector = json_decode($record['embedding'], true);
            $cluster = $this->getClusters($vector, 1)[0];
            $query = 'UPDATE embeddings SET cluster = ? WHERE id = ?';
            $this->db->exec($query, [$cluster, $record['id']]);
            if ($this->logger) $this->logger->success(
                'Chunk {id} assigned to cluster {cluster}', ['id' => $record['id'], 'cluster' => $cluster]
            );
        }
        $handle->closeCursor();
    }

    /**
     * Get the nearest cluster for the given vector
     *
     * @param float[] $vector
     * @param int $num Number of clusters to return
     * @return int[]
     */
    protected function getClusters($vector, $num = 1)
    {
        $query = 'SELECT cluster, centroid FROM clusters ORDER BY COSIM(centroid, ?) DESC LIMIT ?';
        $result = $this->db->queryAll($query, [json_encode($vector), $num]);
        if (!$result) return [];
        return array_column($result, 'cluster');
    }

    /**
     * Check if clustering has been done before
     * @return bool
     */
    protected function hasClusters()
    {
        $query = 'SELECT COUNT(*) FROM clusters';
        return $this->db->queryValue($query) > 0;
    }

    /**
     * Writes TSV files for visualizing with http://projector.tensorflow.org/
     *
     * @param string $vectorfile path to the file with the vectors
     * @param string $metafile path to the file with the metadata
     * @return void
     */
    public function dumpTSV($vectorfile, $metafile)
    {
        $query = 'SELECT * FROM embeddings';
        $handle = $this->db->query($query);

        $header = implode("\t", ['id', 'page', 'created']);
        file_put_contents($metafile, $header . "\n", FILE_APPEND);

        while ($row = $handle->fetch(\PDO::FETCH_ASSOC)) {
            $vector = json_decode($row['embedding'], true);
            $vector = implode("\t", $vector);

            $meta = implode("\t", [$row['id'], $row['page'], $row['created']]);

            file_put_contents($vectorfile, $vector . "\n", FILE_APPEND);
            file_put_contents($metafile, $meta . "\n", FILE_APPEND);
        }
    }
}
