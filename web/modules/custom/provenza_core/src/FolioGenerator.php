<?php

namespace Drupal\provenza_core;

use Drupal\Core\Database\Connection;

/**
 * Generates unique folios such as SOL-2026-0001.
 */
class FolioGenerator {

  public function __construct(protected Connection $database) {}

  /**
   * Returns the next folio for a prefix.
   */
  public function next(string $prefix): string {
    $year = date('Y');
    $like = $this->database->escapeLike($prefix . '-' . $year . '-') . '%';
    $count = (int) $this->database->select('node__field_folio', 'f')
      ->condition('field_folio_value', $like, 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();
    return sprintf('%s-%s-%04d', strtoupper($prefix), $year, $count + 1);
  }

}
