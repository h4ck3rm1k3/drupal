<?php

/**
 * @file
 * Definition of Drupal\file\Tests\UsageTest.
 */

namespace Drupal\file\Tests;

/**
 * Tests file usage functions.
 */
class UsageTest extends FileManagedTestBase {
  public static function getInfo() {
    return array(
      'name' => 'File usage',
      'description' => 'Tests the file usage functions.',
      'group' => 'File API',
    );
  }

  /**
   * Tests file_usage_list().
   */
  function testGetUsage() {
    $file = $this->createFile();
    db_insert('file_usage')
      ->fields(array(
        'fid' => $file->fid,
        'module' => 'testing',
        'type' => 'foo',
        'id' => 1,
        'count' => 1
      ))
      ->execute();
    db_insert('file_usage')
      ->fields(array(
        'fid' => $file->fid,
        'module' => 'testing',
        'type' => 'bar',
        'id' => 2,
        'count' => 2
      ))
      ->execute();

    $usage = file_usage_list($file);

    $this->assertEqual(count($usage['testing']), 2, t('Returned the correct number of items.'));
    $this->assertTrue(isset($usage['testing']['foo'][1]), t('Returned the correct id.'));
    $this->assertTrue(isset($usage['testing']['bar'][2]), t('Returned the correct id.'));
    $this->assertEqual($usage['testing']['foo'][1], 1, t('Returned the correct count.'));
    $this->assertEqual($usage['testing']['bar'][2], 2, t('Returned the correct count.'));
  }

  /**
   * Tests file_usage_add().
   */
  function testAddUsage() {
    $file = $this->createFile();
    file_usage_add($file, 'testing', 'foo', 1);
    // Add the file twice to ensure that the count is incremented rather than
    // creating additional records.
    file_usage_add($file, 'testing', 'bar', 2);
    file_usage_add($file, 'testing', 'bar', 2);

    $usage = db_select('file_usage', 'f')
      ->fields('f')
      ->condition('f.fid', $file->fid)
      ->execute()
      ->fetchAllAssoc('id');
    $this->assertEqual(count($usage), 2, t('Created two records'));
    $this->assertEqual($usage[1]->module, 'testing', t('Correct module'));
    $this->assertEqual($usage[2]->module, 'testing', t('Correct module'));
    $this->assertEqual($usage[1]->type, 'foo', t('Correct type'));
    $this->assertEqual($usage[2]->type, 'bar', t('Correct type'));
    $this->assertEqual($usage[1]->count, 1, t('Correct count'));
    $this->assertEqual($usage[2]->count, 2, t('Correct count'));
  }

  /**
   * Tests file_usage_delete().
   */
  function testRemoveUsage() {
    $file = $this->createFile();
    db_insert('file_usage')
      ->fields(array(
        'fid' => $file->fid,
        'module' => 'testing',
        'type' => 'bar',
        'id' => 2,
        'count' => 3,
      ))
      ->execute();

    // Normal decrement.
    file_usage_delete($file, 'testing', 'bar', 2);
    $count = db_select('file_usage', 'f')
      ->fields('f', array('count'))
      ->condition('f.fid', $file->fid)
      ->execute()
      ->fetchField();
    $this->assertEqual(2, $count, t('The count was decremented correctly.'));

    // Multiple decrement and removal.
    file_usage_delete($file, 'testing', 'bar', 2, 2);
    $count = db_select('file_usage', 'f')
      ->fields('f', array('count'))
      ->condition('f.fid', $file->fid)
      ->execute()
      ->fetchField();
    $this->assertIdentical(FALSE, $count, t('The count was removed entirely when empty.'));

    // Non-existent decrement.
    file_usage_delete($file, 'testing', 'bar', 2);
    $count = db_select('file_usage', 'f')
      ->fields('f', array('count'))
      ->condition('f.fid', $file->fid)
      ->execute()
      ->fetchField();
    $this->assertIdentical(FALSE, $count, t('Decrementing non-exist record complete.'));
  }

  /**
   * Ensure that temporary files are removed.
   *
   * Create files for all the possible combinations of age and status. We are
   * using UPDATE statements because using the API would set the timestamp.
   */
  function testTempFileCleanup() {
    // Temporary file that is older than DRUPAL_MAXIMUM_TEMP_FILE_AGE.
    $temp_old = file_save_data('');
    db_update('file_managed')
      ->fields(array(
        'status' => 0,
        'timestamp' => 1,
      ))
      ->condition('fid', $temp_old->fid)
      ->execute();
    $this->assertTrue(file_exists($temp_old->uri), t('Old temp file was created correctly.'));

    // Temporary file that is less than DRUPAL_MAXIMUM_TEMP_FILE_AGE.
    $temp_new = file_save_data('');
    db_update('file_managed')
      ->fields(array('status' => 0))
      ->condition('fid', $temp_new->fid)
      ->execute();
    $this->assertTrue(file_exists($temp_new->uri), t('New temp file was created correctly.'));

    // Permanent file that is older than DRUPAL_MAXIMUM_TEMP_FILE_AGE.
    $perm_old = file_save_data('');
    db_update('file_managed')
      ->fields(array('timestamp' => 1))
      ->condition('fid', $temp_old->fid)
      ->execute();
    $this->assertTrue(file_exists($perm_old->uri), t('Old permanent file was created correctly.'));

    // Permanent file that is newer than DRUPAL_MAXIMUM_TEMP_FILE_AGE.
    $perm_new = file_save_data('');
    $this->assertTrue(file_exists($perm_new->uri), t('New permanent file was created correctly.'));

    // Run cron and then ensure that only the old, temp file was deleted.
    $this->cronRun();
    $this->assertFalse(file_exists($temp_old->uri), t('Old temp file was correctly removed.'));
    $this->assertTrue(file_exists($temp_new->uri), t('New temp file was correctly ignored.'));
    $this->assertTrue(file_exists($perm_old->uri), t('Old permanent file was correctly ignored.'));
    $this->assertTrue(file_exists($perm_new->uri), t('New permanent file was correctly ignored.'));
  }
}
