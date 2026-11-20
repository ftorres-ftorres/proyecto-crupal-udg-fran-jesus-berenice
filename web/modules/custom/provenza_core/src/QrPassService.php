<?php

namespace Drupal\provenza_core;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\NodeInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Builds QR pass images for visitor authorizations.
 */
class QrPassService {

  public function __construct(
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Generates a PNG QR file for a visit node.
   */
  public function generate(NodeInterface $node): ?FileInterface {
    $token = $node->get('field_codigo_qr')->value ?: ('V-' . $node->uuid());
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $payload = $token . '|' . $url;

    $builder = new Builder(
      writer: new PngWriter(),
      data: $payload,
      size: 360,
      margin: 12,
    );
    $result = $builder->build();

    $directory = 'public://pases-qr';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $filename = $directory . '/' . preg_replace('/[^A-Z0-9\-]/i', '', $token) . '.png';
    return $this->fileRepository->writeData($result->getString(), $filename, FileSystemInterface::EXISTS_REPLACE);
  }

}
