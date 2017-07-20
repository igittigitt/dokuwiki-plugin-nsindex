<?php
/**
 * DokuWiki Plugin nsindex (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Oliver Geisen <oliver@rehkopf-geisen.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class syntax_plugin_nsindex extends DokuWiki_Syntax_Plugin {
    /**
     * @return string Syntax mode type
     */
    public function getType() {
        return 'substition';
    }
    /**
     * @return string Paragraph type
     */
    public function getPType() {
        return 'normal';
    }
    /**
     * @return int Sort order - Low numbers go before high numbers
     */
    public function getSort() {
        return 200;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('{{nsindex[^}]*}}',$mode,'plugin_nsindex');
    }

    /**
     * Handle matches of the nsindex syntax
     *
     * @param string          $match   The match of the syntax
     * @param int             $state   The state of the handler
     * @param int             $pos     The position in the document
     * @param Doku_Handler    $handler The handler
     * @return array Data for the renderer
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        $data = $this->_get_defaults();

        /**
         * parse options and override defaults
         */
        $match = explode(',',trim(substr($match,9,-2)));
        foreach ($match as $m)
        {
            if (strstr($m, '=')) {
                list($opt,$val) = explode('=', $m);
            } else {
                $opt = $m;
                $val = true;
            }
            if (in_array($m, $data))
            {
                $data[$opt] = $val;
            }
        }

        return $data;
    }

    /**
     * Render xhtml output or metadata
     *
     * @param string         $mode      Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer  $renderer  The renderer
     * @param array          $data      The data from the handler() function
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        if($mode != 'xhtml') return false;

        global $conf;

        // Never cache the result to get fresh info
        $renderer->info['cache'] = false;

        // Do not add section edit buttons unter group headers
        $oldmaxecl = $conf['maxseclevel'];
        $conf['maxseclevel'] = 0;

        // To see the TOC, to not use ~~NOTOC~~
        if ($renderer->info['toc']) {
            $oldmaxtoc = $conf['maxtoclevel'];
            $conf['maxtoclevel'] = 3;
        } else {
            $oldmaxtoc = -1;
        }

        // Build the list
        $data = $this->_build_index($data, $renderer);

        // Translate text to HTML for DW page
        $data = p_render('xhtml', p_get_instructions($data), $_dummy);

        // Append to current page
        $renderer->doc .= '<div id="nsindex">' ;
        $renderer->doc .= $data;
        $renderer->doc .= '</div>' ;

        // restore edit level and TOC
        $conf['maxseclevel'] = $oldmaxecl;
        if ($oldmaxtoc == -1) {
            $conf['maxtoclevel'] = $oldmaxtoc;
        }

        return true;
    }

    //------------------------------------------------------------------------//

    /**
     * Default options for nsindex
     */
    private function _get_defaults() {
        return array(
            'nons'       => false,  // exclude start-page of namespaces found
            'nopages'    => false,  // exclude all pages found, include only startpage of sub-namespaces
            'nogroup'    => false,  // do not add alpha-index headings in list
            'group'      => true,   // opposite of 'nogroup'
            'titlesort'  => false,  // order entries by title-heading, not by namespace-name
            'nssort'     => true,   //
            'numlist'    => false,
            'nonumlist'  => false,
            'notemplate' => false,  // omitt pages with names like 'template' or '__template' or '_template'
            'hide'       => false,
            'startns'    => '.',    // where to start lookup (default is current namespace)
        );
    }

    /**
     * Build the index from namespace scan
     */
    private function _build_index($data, &$renderer) {
        global $conf;
        global $ID;

        $opts = $data[0];

        // get content of namespace (pages and subdirs)
        if ( ! $opts['startns'] || $opts['startns'] == '.') {
            $ns = getNS($ID); # current namespace (default)
        } else {
            $ns = $opts['startns'];
            resolve_pageid(getNS($ID), $ns, $exists);
            $ns = getNS($ns);
        }
        $data = array();
        $nsdir = str_replace(':','/',$ns);
        search($data,$conf['datadir'],'search_index',array(),$nsdir,1);

        // filter and sort the list
        $sort = array();
        foreach ($data as $i=>$item) {

            if (noNS($item['id']) == $conf['start']) {
                continue;  # ignore index-page of current namespace
            }

            // get full wikipath and heading of page
            if ($item['type'] == 'd') {
                if ($opts['nons']) {
                    continue;  # ignore namespace
                }
                $wikipath = $item['id'].':'.$conf['start'];
                $data[$i]['id'] = $wikipath;  # change wikipath in data
                $title = p_get_first_heading($wikipath);
                if ( ! $title) {
                    $title = noNS(getNS($wikipath));
                }
            } else {  # page found
                if ($opts['nopages']) {
                    continue;  # ignore pages
                }
                $pn = noNS($item['id']);
                if(($pn == 'template' || $pn == '_template' || $pn == '__template') && $opts['notemplate']) {
                    continue;  # ignore template pages
                }
                $wikipath = $item['id'];
                $title = p_get_first_heading($wikipath);
                if ( ! $title) {
                    $title = $pn;
                }
            }

            // Check for access rights
            if(auth_quickaclcheck($wikipath) < AUTH_READ) {
                continue;  # no access for this page, omitt from list
            }

            // build array for later sort
            $sortkey = cleanID($title);
            $sort[$i] = $sortkey;

            // add metadata
            $data[$i]['title'] = $title;
            $data[$i]['sortkey'] = $sortkey;
            $data[$i]['sortgroup'] = substr($sortkey,0,1);
        }

        // sort the indexed pages
        asort($sort);

        // build pagesource of list
        $txt = '';
        $current_letter = '';
        foreach ($sort as $i=>$sortkey) {
            $title = $data[$i]['title'];

            // show alpha-groups
            if ( ! $opts['nogroup']) {
                $first = strtoupper(substr($data[$i]['sortkey'],0,1));
                if ($first != $current_letter) {
                    $current_letter = $first;
                    $txt .= '===== '.$current_letter.' ====='."\n";
                }
            }

            $txt .= '  * [['.$data[$i]['id'].']]'."\n";
        }

        return $txt;
    }
}

// vim:ts=4:sw=4:et:
