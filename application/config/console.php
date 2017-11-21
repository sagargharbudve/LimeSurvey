<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/*
* LimeSurvey
* Copyright (C) 2007-2017 The LimeSurvey Project Team / Carsten Schmitz
* All rights reserved.
* License: GNU/GPL License v3 or later, see LICENSE.php
* LimeSurvey is free software. This version may have been modified pursuant
* to the GNU General Public License, and as distributed it includes or
* is derivative of works licensed under the GNU General Public License or
* other free or open source software licenses.
* See COPYRIGHT.php for copyright notices and details.
*
*/

/**
* This file contains the default settings for LimeSurvey for command action
* Do not edit this file as it may change in future revisions of the software.
* @package configuration
*/


$config = array();

$config['rootdir']                 = Yii::getPathOfAlias('webroot');

$config['publicdir']               = $config['rootdir']; // The directory path of the public scripts
$config['homedir']                 = $config['rootdir']; // The directory path of the admin scripts
$config['tempdir']                 = $config['rootdir'].DIRECTORY_SEPARATOR."tmp";         // The directory path where LimeSurvey can store temporary files
$config['imagedir']                = $config['rootdir'].DIRECTORY_SEPARATOR."images";      // The directory path of the image directory
$config['uploaddir']               = $config['rootdir'].DIRECTORY_SEPARATOR."upload";
$config['standardthemerootdir'] = $config['rootdir'].DIRECTORY_SEPARATOR."templates";   // The directory path of the standard themes
$config['userthemerootdir']     = $config['uploaddir'].DIRECTORY_SEPARATOR."templates"; // The directory path of the user themes
$config['assets']                  = $config['rootdir'].DIRECTORY_SEPARATOR."assets";
$config['styledir']                = $config['assets'].DIRECTORY_SEPARATOR.'styles-admin';
$config['questiontypedir']         = $config['rootdir'].DIRECTORY_SEPARATOR.'application'.DIRECTORY_SEPARATOR.'extensions'.DIRECTORY_SEPARATOR.'questionTypes';

return $config;
