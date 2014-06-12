<?php if (!defined('TL_ROOT')) die('You cannot access this file directly!');

class ModuleCopypaste extends BackendModule
{
	
	protected $strTemplate   = 'be_copypaste';
	private   $blnRealurl    = false;
	private   $arrExportData = array();


	protected function compile()
	{
		$this->loadLanguageFile('tl_copypaste');
		$this->blnRealurl = in_array('realurl', $this->Config->getActiveModules());

		$this->Template->back                = $GLOBALS['TL_LANG']['MSC']['backBT'];
		$this->Template->backHref            = $this->getReferer(true);
		
		$this->Template->exportHeadline      = $GLOBALS['TL_LANG']['tl_copypaste']['exportHeadline'];
		$this->Template->exportPageAlias     = $GLOBALS['TL_LANG']['tl_copypaste']['exportPageAlias'];
		$this->Template->exportPageAliasHelp = $GLOBALS['TL_LANG']['tl_copypaste']['exportPageAliasHelp'];
		$this->Template->exportRecursive     = $GLOBALS['TL_LANG']['tl_copypaste']['exportRecursive'];
		$this->Template->exportRecursiveHelp = $GLOBALS['TL_LANG']['tl_copypaste']['exportRecursiveHelp'];
		$this->Template->exportSubmit        = $GLOBALS['TL_LANG']['tl_copypaste']['exportSubmit'];
		$this->Template->exportError         = false;

		$this->Template->importHeadline      = $GLOBALS['TL_LANG']['tl_copypaste']['importHeadline'];
		$this->Template->importPageAlias     = $GLOBALS['TL_LANG']['tl_copypaste']['importPageAlias'];
		$this->Template->importPageAliasHelp = $GLOBALS['TL_LANG']['tl_copypaste']['importPageAliasHelp'];
		$this->Template->importData          = $GLOBALS['TL_LANG']['tl_copypaste']['importData'];
		$this->Template->importDataHelp      = $GLOBALS['TL_LANG']['tl_copypaste']['importDataHelp'];
		$this->Template->importSubmit        = $GLOBALS['TL_LANG']['tl_copypaste']['importSubmit'];

		switch ($this->Input->post('FORM_SUBMIT'))
		{
			case 'tl_export': $this->exportPages($this->Input->post('pageAlias'), $this->Input->post('recursive')); break;
			case 'tl_import': $this->importPages($this->Input->post('pageAlias'), $_FILES['data']['tmp_name']); break;
		}
	}
	
	
	private function exportPages($strPageAlias, $blnRecursive)
	{
		$arrPage = $this->getPageByAlias($strPageAlias);

		if (!$arrPage)
		{
			$this->Template->exportError     = 'pageAlias';
			$this->Template->exportErrorText = $GLOBALS['TL_LANG']['tl_copypaste']['pageAliasNotFound'];
			return;
		}

		if ($arrPage['pid'] == 0)
		{
			$this->Template->exportError     = 'pageAlias';
			$this->Template->exportErrorText = $GLOBALS['TL_LANG']['tl_copypaste']['rootPageNotAllowed'];
			return;
		}

		$arrPageAlias = explode('/',$arrPage['alias']);
		
		$this->arrExportData = array
		(
			'rootPageAlias' => end($arrPageAlias),
			'pages'         => array(),
			'articles'      => array(),
			'contents'      => array(),
		);
		
		$this->exportPage($arrPage, $arrInfo, $blnRecursive);
		
		$strFileName = $_SERVER['SERVER_NAME'].'-'.strtr($strPageAlias,'/','-').'-'.date('Y-m-d').'.json';
		file_put_contents(TL_ROOT.'/system/tmp/'.$strFileName, json_encode($this->arrExportData));
		
		$this->Template->exportInfo      = $arrInfo;
		$this->Template->exportDataTitle = $strFileName;
		$this->Template->exportDataUrl   = '/system/tmp/'.$strFileName;
	}
	
	
	private function exportPage($arrPage, &$arrPageInfo, $blnRecursive=true)
	{
		$arrPageInfo = array
		(
			'data'  => $arrPage,
			'pages' => array(),
		);
		
		$this->arrExportData['pages'][$arrPage['id']] = $this->arr($arrPage);
		
		foreach ($this->getArticlesByPid($arrPage['id']) as $arrArticle)
		{
			$this->arrExportData['articles'][$arrArticle['id']] = $this->arr($arrArticle);
			
			foreach ($this->getContentsByPid($arrArticle['id']) as $arrContent)
			{
				$this->arrExportData['contents'][$arrContent['id']] = $this->arr($arrContent);
			}
		}

		if (!$blnRecursive)
			return;
		
		foreach ($this->getPagesByPid($arrPage['id']) as $arrChildPage)
		{
			$this->exportPage($arrChildPage, $arrChildPageInfo);
			$arrPageInfo['pages'][] = $arrChildPageInfo;
		}
	}
	
	
	private function getPageByAlias($strAlias)
	{
		return $this->Database->prepare('
			SELECT *
			FROM tl_page
			WHERE '.($this->blnRealurl ? 'alias' : 'id').' = ?
		')->execute(
			$strAlias
		)->fetchAssoc();
	}


	private function getPagesByPid($intPid)
	{
		return $this->Database->prepare('
			SELECT *
			FROM tl_page
			WHERE pid = ?
			ORDER BY sorting
		')->execute(
			$intPid
		)->fetchAllAssoc();
	}


	private function getArticlesByPid($intPid)
	{
		return $this->Database->prepare('
			SELECT *
			FROM tl_article
			WHERE pid = ?
			ORDER BY sorting
		')->execute(
			$intPid
		)->fetchAllAssoc();
	}


	private function getContentsByPid($intPid)
	{
		return $this->Database->prepare('
			SELECT *
			FROM tl_content
			WHERE pid = ?
			ORDER BY sorting
		')->execute(
			$intPid
		)->fetchAllAssoc();
	}
	
	
	private function arr($arr)
	{
		$intPid = $arr['pid'];
		$arrAlias = explode('/',$arr['alias']);

		unset($arr['id']);
		unset($arr['pid']);
		unset($arr['alias']);

		return array
		(
			'pid'   => $intPid,
			'alias' => end($arrAlias),
			'data'  => $arr,
		);
	}
	
	
	private function importPages($strPageAlias, $strDataFileName)
	{
		$arrParentPage = $this->getPageByAlias($strPageAlias);

		if (!$arrParentPage)
		{
			$this->Template->importError     = 'pageAlias';
			$this->Template->importErrorText = $GLOBALS['TL_LANG']['tl_copypaste']['pageAliasNotFound'];
			return;
		}

		if (!is_uploaded_file($strDataFileName))
		{
			$this->Template->importError     = 'data';
			$this->Template->importErrorText = $GLOBALS['TL_LANG']['tl_copypaste']['noData'];
			return;
		}

		$strData = file_get_contents($strDataFileName);
		$arrData = json_decode($strData, true);

		if ($this->blnRealurl && $this->getPageByAlias($strPageAlias.'/'.$arrData['rootPageAlias']))
		{
			$this->Template->importError     = 'pageAlias';
			$this->Template->importErrorText = $GLOBALS['TL_LANG']['tl_copypaste']['pageAliasAlreadyInUse'];
			return;
		}

		// pages
		$i=0; foreach ($arrData['pages'] as &$arrPage)
		{
			if ($i == 0)
			{
				$arrInfo                  = &$arrPage;
				$arrPage['data']['pid']   = $arrParentPage['id'];
				$arrPage['data']['alias'] = ($this->blnRealurl ? $arrParentPage['alias'].'/' : '').$arrPage['alias'];
			} else {
				$arrParentPage            = &$arrData['pages'][$arrPage['pid']];
				$arrParentPage['pages'][] = &$arrPage;
				$arrPage['data']['pid']   = $arrParentPage['insertId'];
				$arrPage['data']['alias'] = ($this->blnRealurl ? $arrParentPage['data']['alias'].'/' : '').$arrPage['alias'];
			}

			$objPage = $this->Database->prepare('INSERT INTO tl_page %s')->set(
				$arrPage['data']
			)->execute();

			$arrPage['insertId'] = $objPage->insertId;
			$i++;
		}
		unset($arrPage);
		unset($arrParentPage);

		// articles
		foreach ($arrData['articles'] as &$arrArticle)
		{
			$arrParentPage               = $arrData['pages'][$arrArticle['pid']];
			$arrArticle['data']['pid']   = $arrParentPage['insertId'];
			$arrArticle['data']['alias'] = $arrArticle['alias'];

			$objPage = $this->Database->prepare('INSERT INTO tl_article %s')->set(
				$arrArticle['data']
			)->execute();

			$arrArticle['insertId'] = $objPage->insertId;
		}
		unset($arrArticle);

		// contents
		foreach ($arrData['contents'] as &$arrContent)
		{
			$arrParentArticle          = $arrData['articles'][$arrContent['pid']];
			$arrContent['data']['pid'] = $arrParentArticle['insertId'];

			$objPage = $this->Database->prepare('INSERT INTO tl_content %s')->set(
				$arrContent['data']
			)->execute();

			$arrContent['insertId'] = $objPage->insertId;
		}
		unset($arrContent);
		
		if ($this->blnRealurl)
		{
			$this->import('RealUrl');
			$this->RealUrl->createAliasList();
		}

		$this->Template->importInfoText = $GLOBALS['TL_LANG']['tl_copypaste']['importComplete'];
		$this->Template->importInfo     = $arrInfo;
	}


}
