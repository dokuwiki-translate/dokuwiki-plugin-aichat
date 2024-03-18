<?php

namespace dokuwiki\plugin\aichat\Model\OpenAI;

/**
 * OpenAI Client to use the GPT-4 model
 *
 * Chunks are slightly larger for this model
 */
class GPT4 extends GPT35Turbo
{
    /** @inheritdoc */
    public function getModelName()
    {
        return 'gpt-4';
    }

    /** @inheritdoc */
    public function get1MillionTokenPrice()
    {
        // differs between input and output tokens, we use the more expensive one
        return 60.00;
    }

    /** @inheritdoc */
    public function getMaxContextTokenLength()
    {
        return 3000;
    }

    /** @inheritdoc */
    public function getMaxRephrasingTokenLength()
    {
        return 3500;
    }

    /** @inheritdoc */
    public function getMaxEmbeddingTokenLength()
    {
        return 2000;
    }
}
