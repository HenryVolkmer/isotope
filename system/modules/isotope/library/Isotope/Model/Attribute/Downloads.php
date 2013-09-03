<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2012 Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://www.isotopeecommerce.com
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 */

namespace Isotope\Model\Attribute;

use Isotope\Interfaces\IsotopeAttribute;
use Isotope\Interfaces\IsotopeProduct;
use Isotope\Model\Attribute;


/**
 * Attribute to provide downloads in the product details
 *
 * @copyright  Isotope eCommerce Workgroup 2009-2012
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 */
class Downloads extends Attribute implements IsotopeAttribute
{

	public function saveToDCA(array &$arrData)
	{
		parent::saveToDCA($arrData);

		$arrData['fields'][$this->field_name]['sql'] = "blob NULL";
	}

	/**
     * Generate download attributes
     * @param IsotopeProduct
     * @return string
     */
    public function generate(IsotopeProduct $objProduct)
    {
        $arrFiles = $objProduct->{$this->field_name};

        // Return if there are no files
        if (!is_array($arrFiles) || empty($arrFiles))
        {
            return '';
        }

        $file = \Input::get('file', true);

        // Send the file to the browser
        if ($file != '' && (in_array($file, $arrFiles) || in_array(dirname($file), $arrFiles)) && !preg_match('/^meta(_[a-z]{2})?\.txt$/', basename($file)))
        {
            \Controller::sendFileToBrowser($file);
        }

        $files = array();
        $auxDate = array();

        $allowedDownload = trimsplit(',', strtolower($GLOBALS['TL_CONFIG']['allowedDownload']));

        // Get all files
        foreach ($arrFiles as $file)
        {
            if (isset($files[$file]) || !file_exists(TL_ROOT . '/' . $file))
            {
                continue;
            }

            // Single files
            if (is_file(TL_ROOT . '/' . $file))
            {
                $objFile = new File($file);

                if (in_array($objFile->extension, $allowedDownload) && !preg_match('/^meta(_[a-z]{2})?\.txt$/', basename($file)))
                {
                    $this->parseMetaFile(dirname($file), true);
                    $arrMeta = $this->arrMeta[$objFile->basename];

                    if ($arrMeta[0] == '')
                    {
                        $arrMeta[0] = specialchars($objFile->basename);
                    }

                    $files[$file] = array
                    (
                        'link' => $arrMeta[0],
                        'title' => $arrMeta[0],
                        'href' => \Environment::get('request') . (($GLOBALS['TL_CONFIG']['disableAlias'] || strpos(\Environment::get('request'), '?') !== false) ? '&amp;' : '?') . 'file=' . $this->urlEncode($file),
                        'caption' => $arrMeta[2],
                        'filesize' => $this->getReadableSize($objFile->filesize, 1),
                        'icon' => TL_FILES_URL . 'system/themes/' . $this->getTheme() . '/images/' . $objFile->icon,
                        'mime' => $objFile->mime,
                        'meta' => $arrMeta,
                        'extension' => $objFile->extension
                    );

                    $auxDate[] = $objFile->mtime;
                }

                continue;
            }

            $subfiles = scan(TL_ROOT . '/' . $file);
            $this->parseMetaFile($file);

            // Folders
            foreach ($subfiles as $subfile)
            {
                if (is_dir(TL_ROOT . '/' . $file . '/' . $subfile))
                {
                    continue;
                }

                $objFile = new File($file . '/' . $subfile);

                if (in_array($objFile->extension, $allowedDownload) && !preg_match('/^meta(_[a-z]{2})?\.txt$/', basename($subfile)))
                {
                    $arrMeta = $this->arrMeta[$objFile->basename];

                    if ($arrMeta[0] == '')
                    {
                        $arrMeta[0] = specialchars($objFile->basename);
                    }

                    $files[$file . '/' . $subfile] = array
                    (
                        'link' => $arrMeta[0],
                        'title' => $arrMeta[0],
                        'href' => \Environment::get('request') . (($GLOBALS['TL_CONFIG']['disableAlias'] || strpos(\Environment::get('request'), '?') !== false) ? '&amp;' : '?') . 'file=' . $this->urlEncode($file . '/' . $subfile),
                        'caption' => $arrMeta[2],
                        'filesize' => $this->getReadableSize($objFile->filesize, 1),
                        'icon' => 'system/themes/' . $this->getTheme() . '/images/' . $objFile->icon,
                        'meta' => $arrMeta,
                        'extension' => $objFile->extension
                    );

                    $auxDate[] = $objFile->mtime;
                }
            }
        }

        // Sort array
        switch ($this->sortBy)
        {
            default:
            case 'name_asc':
                uksort($files, 'basename_natcasecmp');
                break;

            case 'name_desc':
                uksort($files, 'basename_natcasercmp');
                break;

            case 'date_asc':
                array_multisort($files, SORT_NUMERIC, $auxDate, SORT_ASC);
                break;

            case 'date_desc':
                array_multisort($files, SORT_NUMERIC, $auxDate, SORT_DESC);
                break;

            case 'meta':
                $arrFiles = array();
                foreach ($this->arrAux as $k)
                {
                    if (strlen($k))
                    {
                        $arrFiles[] = $files[$k];
                    }
                }
                $files = $arrFiles;
                break;
        }

        $objTemplate = new \Isotope\Template('ce_downloads');
        $objTemplate->class = $this->field_name;
        $objTemplate->files = array_values($files);

        return $objTemplate->parse();
    }
}