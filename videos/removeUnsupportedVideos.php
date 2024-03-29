<?php
/**
 * removeUnsupportedVideos.php
 *
 * @author Lojjik Braughler
 * 10/23/2014
 *
 * Checks video pages to see if the embedded
 * video is still available. If not, it will
 * remove all video sections from linked articles
 * and delete the video page
 */

define( 'MAINT_DIR', dirname( dirname( __DIR__ ) ) );

require_once MAINT_DIR . '/Maintenance.php';
require_once MAINT_DIR . '/wikihow/videos/EmbeddedVideo.php';
require_once MAINT_DIR . '/wikihow/videos/VideoProvider.php';

class RemoveUnsupportedVideos extends Maintenance {
	const MAX_CHECK_VIDEOS = 1000;
	const MAX_REMOVE_VIDEOS = 25;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'test', 'Whether to actually delete videos or just test.', false, false,
			't' );
	}

	public function execute() {
		$removedVideos = 0;

		if ( $this->hasOption( 'test' ) ) {
			$this->output( "Notice: Running in sandbox mode. Let's build a castle.\n\n" );
		}

		$this->output( "Pulling video list ........ " );
		$videos = $this->getVideos();
		$this->output( "Done, " . count( $videos ) . " videos to sort through.\n" );

		foreach ( $videos as $video ) {
			if ( !$video->isAvailable() ) {

				if ( $this->hasOption( 'test' ) ) {
					$this->output( "*** Would remove video: " . $video->getDBKey() . " ***\n" );
				} else {
					if ( $removedVideos < self::MAX_REMOVE_VIDEOS ) {
						$this->output( "*** Removing video: " . $video->getDBKey() . " . It had provider URL: " . $video->getProviderURL() . " ***\n" );
						$video->remove();
						$removedVideos++;
					} else {
						$this->output(" *** Not removing video: " . $video->getDBKey() . " because we've already removed more than " . self::MAX_REMOVE_VIDEOS . " videos ***\n");
						$sendMail = true;
					}
				}
			}
		}

		if ( $sendMail ) {

			$recipients = array( new MailAddress( User::newFromName( 'Reuben' ) ),
								new MailAddress( User::newFromName( 'Lojjik' ) )
					);

			$body = 'While running the removeUnsupportedVideos.php maintenance script, we hit the limit of ' . self::MAX_REMOVE_VIDEOS . ' for deleted videos.';
			$sender = new MailAddress( 'Vidbot <alerts@wikihow.com>' );

			// have to send individually on our set up
			foreach ( $recipients as $recipient ) {
				UserMailer::send($recipient, $sender, 'Alert: Video removal limit exceeded', $body);
			}

		}
	}

	public function getOffset() {

		// what row + 1 to start working from in the db. ex: offset 0 starts at row 1
		// this is calculated based on a period so that all videos will be covered
		// once per period. increase the number of nightly videos
		// if you want a shorter period (time between checks)

		$dbr = wfGetDB( DB_SLAVE );
		$totalVideos = $dbr->selectField( array(
			'page'
		), array(
			'COUNT(*)'
		), array(
			'page_namespace' => NS_VIDEO,
			'page_is_redirect' => 0
		) );

		$dayOfYear = (int)date( "z" );
		$cyclePeriod = ceil( $totalVideos / self::MAX_CHECK_VIDEOS );
		$offset = ($dayOfYear % $cyclePeriod) * self::MAX_CHECK_VIDEOS;
		return $offset;
	}

	public function getVideos() {
		$videos = array();

		$dbr = wfGetDB( DB_SLAVE );
		$offset = $this->getOffset();

		$res = $dbr->select( 'page', array(
			'page_id'
		), array(
			'page_namespace' => NS_VIDEO,
			'page_is_redirect' => 0
		), __METHOD__,
			array(
				'OFFSET' => $offset,
				'LIMIT' => self::MAX_CHECK_VIDEOS
			) );

		foreach ( $res as $row ) {
			$videos[] = new EmbeddedVideo( $row->page_id );
		}

		return $videos;
	}
}

$maintClass = 'RemoveUnsupportedVideos';
require_once RUN_MAINTENANCE_IF_MAIN;
