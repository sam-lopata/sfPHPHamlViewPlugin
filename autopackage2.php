<?php

/**
 * script to automate the generation of the
 * package.xml file.
 * 
 * @author      Semen Bocharov
 * @package     sfPHPHamlViewPlugin
 */

require_once 'PEAR/PackageFileManager2.php';

/**
 * Base version
 */
$baseVersion = '1.0.0';

/**
 * current version
 */
$version  = $baseVersion;
$dir      = dirname( __FILE__ );

/**
 * Current API version
 */
$apiVersion = '1.0.0';

/**
 * current state
 */
$state = 'beta';

/**
 * current API stability
 */
$apiStability = 'beta';

/**
 * release notes
 */
$notes = <<<EOT
--
EOT;

/**
 * package description
 */
$description = "Gives a symfony powered project to make use of the Haml template engine.";

$package = new PEAR_PackageFileManager2();

$result = $package->setOptions(array(
  'filelistgenerator' => 'file',
  'ignore'            => array('autopackage2.php', 'package.xml', '.svn'),
  'simpleoutput'      => true,
  'baseinstalldir'    => 'sfPHPHamlViewPlugin/',
  'packagedirectory'  => $dir
));
if (PEAR::isError($result)) {
  echo $result->getMessage();
  die();
}

$package->setPackage('sfPHPHamlViewPlugin');
$package->setSummary('Haml template engine support for views in symfony >=1.2');
$package->setDescription($description);

$package->setChannel('pear.symfony-project.com');
$package->setAPIVersion($apiVersion);
$package->setReleaseVersion($version);
$package->setReleaseStability($state);
$package->setAPIStability($apiStability);
$package->setNotes($notes);
$package->setPackageType('php');
$package->setLicense('MIT License', 'http://www.symfony-project.com/license');

$package->addMaintainer('lead', 'sam_lopata', 'Semen Bocharov', 'sam.lopata@gmail.com', 'yes');

$package->setPhpDep('5.1.0');
$package->setPearinstallerDep('1.4.1');

$package->addPackageDepWithChannel(
  'required',
  'symfony',
  'pear.symfony-project.com',
  '1.2.0',
  '1.4.2',
  false
);

$package->generateContents();

$result = $package->writePackageFile();
if (PEAR::isError($result)) {
    echo $result->getMessage();
    die();
}
