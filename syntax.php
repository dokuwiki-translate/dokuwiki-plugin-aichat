<?php

/**
 * DokuWiki Plugin aichat (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */
class syntax_plugin_aichat extends \dokuwiki\Extension\SyntaxPlugin
{
    /** @inheritDoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritDoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 155;
    }

    /** @inheritDoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<aichat(?: [^>]+)*>.*?(?:<\/aichat>)', $mode, 'plugin_aichat');
    }


    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 7, -9);
        [$params, $body] = explode('>', $match, 2);
        $params = explode(' ', $params);

        return ['params' => $params, 'body' => $body];
    }

    /** @inheritDoc */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') {
            return false;
        }

        $opts = [
            'hello' => trim($data['body']),
            'url' => DOKU_BASE . '/lib/exe/ajax.php?call=aichat',
        ];
        $html = '<aichat-chat ' . buildAttributes($opts) . '></aichat-chat>';

        if (in_array('button', $data['params'])) {
            $html = '<aichat-button>' . $html . '</aichat-button>';
        }

        $renderer->doc .= $html;
        return true;
    }
}

