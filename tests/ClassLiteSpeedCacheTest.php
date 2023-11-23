<?php

namespace WPSL\LiteSpeedCache;

use PHPUnit\Framework\TestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use wpCloud\StatelessMedia\WPStatelessStub;
use wpCloud\StatelessMedia\Utility;

/**
 * Class ClassLiteSpeedCacheTest
 */
class ClassLiteSpeedCacheTest extends TestCase {

  // Adds Mockery expectations to the PHPUnit assertions count.
  use MockeryPHPUnitIntegration;

  const TEST_URL = 'https://test.test';
  const UPLOADS_URL = self::TEST_URL . '/uploads';
  const PNG_FILE = 'image.png';
  const WEBP_FILE = 'image.webp';
  const PNG_URL = self::UPLOADS_URL . '/' . self::PNG_FILE;
  const PNG_GCS_URL = WPStatelessStub::TEST_GS_HOST . '/' . self::PNG_FILE;
  const WEBP_GCS_URL = WPStatelessStub::TEST_GS_HOST . '/' . self::WEBP_FILE;
  const TEST_UPLOAD_DIR = [
    'baseurl' => self::UPLOADS_URL,
    'basedir' => '/var/www/uploads'
  ];

  public function setUp(): void {
		parent::setUp();
		Monkey\setUp();

    // WP mocks
    Functions\when('wp_upload_dir')->justReturn( self::TEST_UPLOAD_DIR );
    Functions\when('update_post_meta')->justReturn( true );

    // WP_Stateless mocks
    Functions\when('ud_get_stateless_media')->justReturn( WPStatelessStub::instance() );
  }

  public function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

  public function testShouldInitModule() {
    $liteSpeedCache = new LiteSpeedCache();

    $liteSpeedCache->module_init([]);
    
    self::assertNotFalse( has_action('litespeed_img_pull_ori', [ $liteSpeedCache, 'sync_image' ]) );
    self::assertNotFalse( has_action('litespeed_img_pull_webp', [ $liteSpeedCache, 'sync_webp' ]) );
    self::assertNotFalse( has_action('litespeed_media_del', [ $liteSpeedCache, 'litespeed_media_del' ]) );
    self::assertNotFalse( has_action('litespeed_media_rename', [ $liteSpeedCache, 'litespeed_media_rename' ]) );
    self::assertNotFalse( has_action('sm:synced::image', [ $liteSpeedCache, 'manual_sync_backup_file' ]) );
    self::assertNotFalse( has_action('sm:pre::synced::image', [ $liteSpeedCache, 'update_md5_and_manual_sync' ]) );
    
    self::assertNotFalse( has_filter('wp_stateless_generate_cloud_meta', [ $liteSpeedCache, 'cloud_meta_add_file_md5' ]) );
    self::assertNotFalse( has_filter('litespeed_media_check_ori', [ $liteSpeedCache, 'litespeed_media_check_img' ]) );
    self::assertNotFalse( has_filter('litespeed_media_check_webp', [ $liteSpeedCache, 'litespeed_media_check_img' ]) );
    self::assertNotFalse( has_filter('litespeed_media_info', [ $liteSpeedCache, 'litespeed_media_info' ]) );
  }

  public function testShouldSyncImage() {
    $liteSpeedCache = new LiteSpeedCache();

    // Mocks
    Filters\expectApplied('wp_stateless_file_name')
      ->once()
      ->andReturn( self::PNG_FILE );

    Filters\expectApplied('litespeed_conf')
      ->once()
      ->andReturn( false );

    Functions\when('get_post_meta')->justReturn(
      [
        'fileMd5' => [
          self::PNG_FILE => 'md5',
        ]
      ]
    );

    // Expectations
    Actions\expectDone('sm:sync::copyFile')->once();
    Actions\expectDone('sm:sync::syncFile')->once();

    $data = [
      'src'       => self::PNG_URL,
      'post_id'   => 15,
    ];

    $liteSpeedCache->sync_image(
      (object) $data,
      self::PNG_FILE,
    );
  }

  public function testShouldSyncWebp() {
    $liteSpeedCache = new LiteSpeedCache();

    // Mocks
    Filters\expectApplied('wp_stateless_file_name')
      ->once()
      ->andReturn( self::WEBP_FILE );

    Filters\expectApplied('litespeed_conf')
      ->once()
      ->andReturn( true );

    Functions\when('get_post_meta')->justReturn([]);

    // Expectations
    Actions\expectDone('sm:sync::syncFile')->once();

    $data = [
      'src'       => self::WEBP_FILE,
      'post_id'   => 15,
    ];

    $liteSpeedCache->sync_webp(
      (object) $data,
      self::WEBP_FILE,
    );

    self::assertNotFalse( has_filter('upload_mimes', [ $liteSpeedCache, 'add_webp_mime' ]) );
  }

  public function testShouldCheckLiteSpeedImage() {
    $liteSpeedCache = new LiteSpeedCache();

    $this->assertTrue(
      $liteSpeedCache->litespeed_media_check_img(null, self::PNG_GCS_URL)
    );

    $this->assertEquals(
      'test',
      $liteSpeedCache->litespeed_media_check_img('test', self::PNG_URL)
    );

  }

  public function testShouldReturnMediaInfo() {
    $liteSpeedCache = new LiteSpeedCache();

    $metadata = [
      'gs_link'   => self::PNG_GCS_URL,
      'file'      => self::PNG_FILE,
    ];

    $cloudMeta = [
      'fileMd5'   => [
        self::PNG_FILE => 'md5',
      ],
    ];

    Functions\when('wp_get_attachment_metadata')->justReturn( $metadata );
    Functions\when('get_post_meta')->justReturn( $cloudMeta );

    $expected = [
      'url'   => self::PNG_GCS_URL,
      'md5'   => 'md5',
      'size'  => 1,
    ];

    $this->assertEquals(
      json_encode( $expected ),
      json_encode(  $liteSpeedCache->litespeed_media_info(null, self::PNG_FILE, 15) )
    );
  }

  public function testShouldReturnDefaultMediaInfo() {
    $liteSpeedCache = new LiteSpeedCache();

    Functions\when('wp_get_attachment_metadata')->justReturn([]);
    Functions\when('get_post_meta')->justReturn([]);

    $this->assertEquals(
      'test',
      $liteSpeedCache->litespeed_media_info('test', self::PNG_FILE, 0)
    );

    $this->assertEquals(
      'test',
      $liteSpeedCache->litespeed_media_info('test', self::PNG_FILE, 15)
    );
  }

  public function testShouldDeleteMedia() {
    $liteSpeedCache = new LiteSpeedCache();

    Functions\when('wp_get_attachment_metadata')->justReturn([]);

    Actions\expectDone('sm:sync::deleteFile')->once()->with(self::PNG_FILE);

    $liteSpeedCache->litespeed_media_del(self::PNG_FILE, 15);
  }

  public function testShouldRenameMedia() {
    $liteSpeedCache = new LiteSpeedCache();

    Filters\expectApplied('wp_stateless_file_name')
      ->twice()
      ->andReturn( self::PNG_FILE );

    Actions\expectDone('sm:sync::moveFile')->once();
    
    Functions\when('get_post_meta')->justReturn([
      'fileMd5'   => [
        self::PNG_FILE => 'md5',
      ],
    ]);

    $liteSpeedCache->litespeed_media_rename(self::PNG_FILE, self::WEBP_FILE, 15);
  }

  public function testShouldAddFileMd5() {
    $liteSpeedCache = new LiteSpeedCache();

    $result = $liteSpeedCache->cloud_meta_add_file_md5(
      [],
      [ 'name' => self::PNG_GCS_URL ],
      null,
      [ 'gs_name' => self::PNG_GCS_URL, 'path' => self::PNG_GCS_URL ],
      [],
      null
    );

    $this->assertEquals(
      json_encode( $result ),
      json_encode([
        'fileMd5' => [
          self::PNG_GCS_URL => 'md5',
        ]
      ])
    );
  }

  public function testShouldManualSyncBackupFiles() {
    $liteSpeedCache = new LiteSpeedCache();

    $cloudMeta = [
      'fileMd5'   => [
        self::PNG_FILE => 'md5',
        self::WEBP_FILE => 'md5',
      ],
    ];

    Functions\when('get_post_meta')->justReturn( $cloudMeta );
    Filters\expectApplied('wp_stateless_handle_root_dir')
      ->once()
      ->andReturn( 'uploads' );

    Actions\expectDone('sm:sync::syncFile')->twice();

    $liteSpeedCache->manual_sync_backup_file(15, null);
  }

  public function testShouldUpdateMd5AndManualSync() {
    $liteSpeedCache = new LiteSpeedCache();

    $imageSizes = [
      [
        'gs_name'   => self::PNG_GCS_URL, 
        'path'      => self::PNG_GCS_URL, 
      ],
      [
        'gs_name'   => self::WEBP_GCS_URL, 
        'path'      => self::WEBP_GCS_URL, 
      ],
    ];

    Utility::set_path_and_url($imageSizes);

    Functions\when('get_post_meta')->justReturn([]);
    Functions\when('wp_get_attachment_metadata')->justReturn([]);
    Filters\expectApplied('litespeed_conf')->andReturn( false );
    Filters\expectApplied('wp_stateless_file_name')->andReturn( self::PNG_FILE );

    Actions\expectDone('sm:sync::syncFile')->twice();

    $liteSpeedCache->update_md5_and_manual_sync(15);
  }
}

function pathinfo() {
  return 'png';
}

function md5_file() {
  return 'md5';
}

function file_exists() {
  return true;
}