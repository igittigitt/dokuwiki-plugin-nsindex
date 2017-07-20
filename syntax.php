<?php
/**
 * Dokuwiki plugin "NSindex" creates an dynamic list of namespace content
 *
 * Syntax: '{{nsindex' [' ' OPTION ','..] '}}'
 * OPTIONs may be:
 *  'nopages' => only show namespace links, no pages.
 *  'nons'    => only show pages in current namespace, nu sub-namespaces.
 *  'nogroup' => do not group list by their first letters
 * TODO:
 *  'titlesort'
 *  'namesort'
 *  'numlist'
 *  'nonumlist'
 *  'notemplate' => alias for 'hide:template'
 *  'hide:<PAGENAME>'
 *
 * NOTES:
 *  - The 'start'-page of the current namespace is omitted from the list
 *  - Pages using this plugin are not cached at all (no '~~NOCACHE~~' needed)
 *
 * @author Oliver Geisen <oliver.geisen@kreisbote.de>
 * @version 1.3
 * @date 10.07.2012
 */

if(!defined('DOKU_INC'))
	define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN'))
	define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
require_once(DOKU_INC.'inc/search.php');

class syntax_plugin_nsindex extends DokuWiki_Syntax_Plugin {

  /**
   * return some info
   */
  function getInfo(){
    return array(
		 'author' => 'Oliver Geisen',
		 'email'  => 'oliver.geisen@kreisbote.de',
		 'date'   => '2008-10-28',
		 'name'   => 'NSindex',
		 'desc'   => 'Create dynamic list of current namespace contents.',
		 'url'    => 'http://dokuwiki-sv.kreisbote.de/src/plugins/kb_nsindex.tar.gz'
		 );
  }

  function getType(){ return 'substition';}

  function getAllowedTypes()
	{
		return array('baseonly', 'substition', 'formatting', 'paragraphs', 'protected');
	}

  /**
   * Where to sort in?
   */
  function getSort()
	{
    return 139;
  }

  /**
   * Connect pattern to lexer
   */
  function connectTo($mode)
	{
    $this->Lexer->addSpecialPattern('{{nsindex.*?}}',$mode,'plugin_nsindex');
  }

	/**
	 * Handle the match
	 */
	function handle($match, $state, $pos, Doku_Handler $handler)
	{
		/**
	   * Default values of all available options
		 */
		$opts = array(
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

		$match = explode(',',trim(substr($match,9,-2)));
		foreach ($match as $m)
		{
			if (strstr($m, '=')) {
				list($opt,$val) = explode('=', $m);
			} else {
				$opt = $m;
				$val = true;
			}
			if (in_array($m, $opts))
			{
				$opts[$opt] = $val;
			}
		}
/*
		foreach($opts as $opt => $val)
		{
			if(in_array($opt,$match))
			{
				$opts[$opt] = true;
			}
		}
*/
		return array($opts);
	}


	/**
	 * Render output
	 */
	function render($mode, Doku_Renderer $renderer, $data)
	{
		global $ID;
		global $conf;

		if($mode == 'xhtml')
		{
	    // Never cache the result to get fresh info
	    $renderer->info['cache'] = false;

	    // Do not add section edit buttons unter group headers
	    $oldmaxecl = $conf['maxseclevel'];
	    $conf['maxseclevel'] = 0;

	    // To see the TOC, to not use ~~NOTOC~~
			if ($renderer->info['toc'])
	    {
				$oldmaxtoc = $conf['maxtoclevel'];
				$conf['maxtoclevel'] = 3;
	    }
	    else
	    {
				$oldmaxtoc = -1;
	    }

	    // Render the list
	    $data = $this->_build_index($data, $renderer);

			// translate text to HTML for DW page
	    $data = p_render('xhtml', p_get_instructions($data), $_dummy);

			// append to current page
	    $renderer->doc .= '<div id="nsindex">' ;
	    $renderer->doc .= $data;
	    $renderer->doc .= '</div>' ;

			// restore edit level and TOC
	    $conf['maxseclevel'] = $oldmaxecl;
	    if ($oldmaxtoc == -1)
				$conf['maxtoclevel'] = $oldmaxtoc;

	    return true;
		}

		return false;
	}


	/**
	 * Build the index from namespace scan
	 */
	private function _build_index($data, &$renderer)
	{
		global $conf;
		global $ID;

		$opts = $data[0];
#print "<pre>"; print_r($data); print "</pre>";
#print "<pre>"; print_r($opts); print "</pre>";

		// get content of namespace (pages and subdirs)
		if ( ! $opts['startns'] || $opts['startns'] == '.')
		{
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
		foreach ($data as $i=>$item)
		{
			# ignore index-page of current namespace
	    if (noNS($item['id']) == $conf['start'])
	    {
				continue;
	    }

			# get full wikipath and heading of page
	    if ($item['type'] == 'd')
	    {
				# namespace found

				if ($opts['nons'])
				{
					continue; // ignore namespace
				}

				$wikipath = $item['id'].':'.$conf['start'];
				$data[$i]['id'] = $wikipath; // change wikipath in data
				$title = p_get_first_heading($wikipath);
				if ( ! $title)
				{
					$title = noNS(getNS($wikipath));
				}
	    }
	    else
	    {
				# page found

				if ($opts['nopages'])
				{
					continue; // ignore pages
				}

				$pn = noNS($item['id']);
				if(($pn == 'template' || $pn == '_template' || $pn == '__template') && $opts['notemplate'])
				{
					continue; // ignore template pages
				}

				$wikipath = $item['id'];
				$title = p_get_first_heading($wikipath);
				if ( ! $title)
				{
					$title = $pn;
				}
	    }

			# Check for access rights
			if(auth_quickaclcheck($wikipath) < AUTH_READ)
			{
				continue; // no access for this page, omitt from list
			}

			# build array for later sort
			$sortkey = cleanID($title);
			$sort[$i] = $sortkey;

			# add metadata
			$data[$i]['title'] = $title;
			$data[$i]['sortkey'] = $sortkey;
			$data[$i]['sortgroup'] = substr($sortkey,0,1);
		}

		// sort the indexed pages
		asort($sort);

		// build pagesource of list
		$txt = '';
		$current_letter = '';
		foreach ($sort as $i=>$sortkey)
		{
			$title = $data[$i]['title'];

			# show alpha-groups
			if ( ! $opts['nogroup'])
			{
				$first = strtoupper(substr($data[$i]['sortkey'],0,1));
				if ($first != $current_letter)
				{
					$current_letter = $first;
					$txt .= '===== '.$current_letter.' ====='."\n";
				}
			}

			$txt .= '  * [['.$data[$i]['id'].']]'."\n";
		}

		return $txt;
	}

}
// vim: set sw=2 ts=2 enc=utf-8 syntax=php :
