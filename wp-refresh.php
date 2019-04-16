<?php
/**
 * WP-Refresh 
 * WordPress core backup and reinstall tool
 **/

ini_set('display_errors','On');

// BACKUP ESSENTIAL FILES FIRST
function backUp(){

	$adm = 'wp-admin';
	$inc = 'wp-includes';
	$archive = 'core_files_bk_'. date("m.d.Y").'.zip';

	class FlxZipArchive extends ZipArchive {

	public function addDir($location, $name) {
		$this->addEmptyDir($name);

		$this->addDirDo($location, $name);
	}

// Add files and directories to the archive or fail
	private function addDirDo($location, $name) {
		$location .= '/';
		$name .= '/';

		$dir = opendir ($location);
		while ($file = readdir($dir))
			{
				if ($file == '.' || $file == '..') continue;
				$do = (filetype( $location . $file) == 'dir') ? 'addDir' : 'addFile';
				$this->$do($location . $file, $name . $file);
			}
		}
	}

	$za = new FlxZipArchive;
	$res = $za->open($archive, ZipArchive::CREATE);
	if($res === TRUE) 
	{
		$za->addDir($adm, basename($adm));
		$za->addDir($inc, basename($inc));
		$za->addFile('wp-config.php', 'wp-config.php');
		$za->close();
	}
	else  { echo 'Could not create a zip archive';}
}

// REINSTALL CORE WORDPRESS FILES
class WpRefresh {
	/**
	 * @var String|Null version
	 *	Holds the detected (or manually set) version of WordPress
	**/
	private $version = NULL;
	/**
	 * @var String wp_download_url
	 * Holds the URL prefix WordPress Archives are downloaded from.
	**/
	private $wp_download_url = 'http://wordpress.org/wordpress-';
	/**
	 * @var String archive_type
	 * File extension of the archive type to download from WordPress.org
	**/
	private $archive_type = 'zip';
	/**
	 *	@var Array errors
	 * Holds errors during the script run
	 **/
	private $errors = array();
	/**
	 *	@var Array notice
	 * Holds notice during the script run
	 **/
	private $notice = array();
	/**
	 *	default folder to run scripts
	 **/
	private $defFolder = 'sitelockWordpressBackup';
	
	public function __construct() {
		if(isset($_POST['version'])) {
			$this->setVersion($_POST['version']);
		}
		if(isset($_POST['go'])) {
			$this->init();
		}
	}
	/**
	 * @method init
	 * Main call function. This is where the magic happens.
	**/
	public function init() {
		if($this->version !== NULL) {
			try {
				$this->downloadArchive();
			} catch (Exception $e) {
				$this->handleException($e);
			}
			
			try {
				$this->unzip();
			} catch (Exception $e) {
				$this->handleException($e);
			}
			//TODO: why are we overwriting?  this will not remove bad files, just fix broken ones.
			try {
				$this->overwrite(getcwd().'/'.$this->defFolder.'/'.'wordpress', getcwd());//TODO: now pulling from defined extraction folder
			} catch (Exception $e) {
				$this->handleException($e);
			}
			//nuke the folder we extracted to.
			$this->delDir(getcwd().'/'.$this->defFolder.'/');//NOTE: this REQUIRES php 5.2+.

		}
		
	}
	/**
	 * @method setVersion
	 * Public setter for version veriable
	 * 
	 * @var String version
	 * The version to set in-class
	**/
	public function setVersion($version) {
		$this->version = $version;
	}
	/**
	 * @method getVersion
	 * Public getter for version veriable
	 * 
	 * @return String version
	**/
	public function getVersion() {
		return $this->version;
	}
	/**
	 * @method downloadArchive
	 * Downloads the WordPress archive of the version requested to the local server.
	 * @throws Exception
	**/
	private function downloadArchive() {
		$download_url = $this->wp_download_url . $this->version . '.' . $this->archive_type;
		if(!copy($download_url, $this->version . '.' . $this->archive_type)) {
			throw new Exception('Failed to copy WordPress.org archive to local host.');
		}
	}
	/**
	 * @method unzip
	 * Unzips the downloaded WordPress archive into CWD/wordpress/
	 * @throws Exception
	**/
	private function unzip() {
		$zip = new ZipArchive();
		if($zip->open($this->version . '.' . $this->archive_type)) {
			$zip->extractTo(getcwd().'/'.$this->defFolder.'/');//TODO: now extracting to defFolder to prevent overwriting existing sites
			$zip->close();
		} else {
			throw new Exception('Failed to expand zip archive.');
		}
	}
	/**
	 * @method overwrite
	 * Takes the unzipped WordPress files and moves them to the installation DIR
	 **/
	private function overwrite($source, $destination) {  //TODO: figure out why this fails at life.
	
		$source = $source . '/';
		$destination = $destination . '/';
		//if($source != 'wordpress/') {
		//	$destination = preg_replace('/^\.\//', '', $destination);
		//}
		// Get array of all source files
		$files = scandir($source);
		// Cycle through all source files
		foreach ($files as $file) {
		  if(in_array($file, array(".",".."))) continue;
		  if(is_dir($source . $file)) {//
		  	$this->overwrite($source . $file ,$destination.$file);//recursion is recursion is
		  	continue;
		  }
		  // If we copied this successfully, mark it for deletion
		  if(!copy($source.$file, $destination.$file)) {
		  	$e = new Exception('Failed to overwrite core file.'. $destination.$file."<br />");
			$this->handleException($e);
		  } else {
		  	$this->addNotice("Copied - \"" . $source . $file . "\" TO \"" . $destination.$file . "\"<br />");
		  }
		}
	}
	/**
	 * @method errors
	 * Displays errors encountered during the run.
	**/
	public function errors() {
		$result = "";
		foreach($this->errors as $err) {
			$result .= $err . "\n";
		}
		return $result;
	}
	public function delDir($dir)
	{
		
		$it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
		$files = new RecursiveIteratorIterator($it,
					 RecursiveIteratorIterator::CHILD_FIRST);
		foreach($files as $file) {
			if ($file->isDir()){
				rmdir($file->getRealPath());
			} else {
				unlink($file->getRealPath());
			}
		}
		rmdir($dir);
	}
	/**
	 * @method notices
	 * Displays notices encountered during the run.
	**/
	public function notices() {
		$result = "";
		foreach($this->notice as $nt) {
			$result .= $nt . "\n";
		}
		return $result;
	}
	/**
	 * @method addError
	 * Adds an error to the error array
	 *
	 * @var String $error
	**/
	private function addError($error) {
		$this->errors[] = $error;
	}
	/**
	 * @method addNotice
	 * Adds an notice to the notice array
	 *
	 * @var String $notice
	**/
	private function addNotice($notice) {
		$this->notice[] = $notice;
	}
	/**
	 * @method handleException
	 * Prints exceptions to the screen and kills the script.
	**/
	private function handleException(Exception $e) {
		$this->addError($e->getMessage());
	}
}

//Main execution path
	
$refresh = New WpRefresh();

if (is_file('wp-includes/version.php'))
{
    require_once('wp-includes/version.php');
		$refresh->setVersion($wp_version); //should be pulling file contents, not an include.
}
else
{
	echo("no wp-includes/version.php file, assuming 4.4.2");
	$refresh->setVersion('4.4.2');
}

if(isset($_GET['action'])=='backUp') {
    backUp();
}else{}
//show form
?>
<html lang="en">
    <head>
        <title>WP Refresh</title>
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap-theme.min.css">
    </head>
    <body>
        <div class="container-fluid">
            <div class="col-md-6">
            <label for="backup">Backup Critical Files</label>
                <form action="?action=backUp" method="POST">
                    <div class="form-group">
                    <input type="hidden" name="versioninfo" value="<?php echo $refresh->getVersion(); ?>">
                        <button type="submit" name="go" value="1" class="btn btn-success btn-lg btn-block">Backup</button>
                    </div>
                </form>
                <p class="bg-warning">
                Note: Backing up the files can take a moment. If the page is spinning, the backup is running. Wait until the save dialogue box is closed before you click <strong>Refresh</strong>.
                </p>
                    <form action="wp-refresh.php" method="POST">
                    <div class="form-group">
                        <label for="version">WordPress Version <?php if($refresh->getVersion() !== NULL) echo '<span class="text-info">Auto-detected from wp-includes/version.php</span>'; ?></label>
                        <input type="text" class="form-control" id="version" name="version" value="<?php echo $refresh->getVersion(); ?>" placeholder="4.4.2" />
                        </div>
                    <div class="form-group">
                        <button type="submit" name="go" value="1" class="btn btn-success btn-lg btn-block">Refresh</button>
                    </div>
                </form>
                <p class="bg-warning">
                Note: This script is not meant to install new versions of WordPress or upgrade - if the correct directory structure is not in place, it <strong>will</strong> fail!
                </p>
                <br />
                <p class="bg-danger">
                    <?php echo $refresh->errors(); ?>
                </p>
                <p class="bg-success">
                    <?php echo $refresh->notices(); ?>
                </p>
            </div>
        </div>
    </body>
</html>