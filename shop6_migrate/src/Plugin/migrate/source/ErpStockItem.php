<?php

namespace Drupal\shop6_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Database;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;
use Drupal\node\Entity\Node;
use Drupal\shop6_migrate\Shop6MigrateUtilities;

/**
 * Migration of contacts from drupal6 erp system.
 *
 * @MigrateSource(
 *   id = "upgrade_d6_stock_item",
 *   source_module = "erp_stock_item"
 * )
 */
class ErpStockItem extends SqlBase {
  use Shop6MigrateUtilities;

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('erp_stock', 'es');
    $query->fields('es');

    $query->leftJoin('erp_item', 'ei', 'es.stock_nid = ei.nid');
    $query->fields('ei');

    $query->leftJoin('node', 'n', 'n.nid = es.stock_nid');
    $query->fields('n');

    $query->orderBy('stock_nid', ErpCore::IMPORT_MODE);

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'stock_id'           => $this->t('Stock id'),
      'stock_nid'          => $this->t('Stock nid'),
      'serial'             => $this->t('Serial'),
      'purchase_order_nid' => $this->t('Purchase order nid'),
      'receipt_nid'        => $this->t('Receipt nid'),
      'receipt_price'      => $this->t('Receipt price'),
      'sell_date'          => $this->t('Sell date'),
      'sell_price'         => $this->t('Sell price'),
      'store_nid'          => $this->t('Stock nid'),
      'lost'               => $this->t('Lost'),
    ];

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'stock_id' => [
        'type'  => 'integer',
        'alias' => 'es',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }

//    // Connect to the old database to query the data table.
//    $db = Database::getConnection('default', 'drupal_6');
//    /** @var \Drupal\Core\Database\Query\Select $query */
//    $query = $db->select('node', 'n');
//    $query->fields('n');
//    $query->condition('n.nid', $row->getSourceProperty('stock_nid'));
//    $query->join('content_type_erp_item', 'ctei', 'ctei.nid = n.nid AND ctei.vid = n.vid');
//    $query->fields('ctei');
//    $result = $query->execute();
//    $items = $result->fetchAll();
//    $item = reset($items);

//    $result = \Drupal::entityQuery('node')
//      ->condition('type', 'erp_item', 'IN')
//      ->condition('status', 1)
//      ->condition('nid', )
//      ->execute();
//    $item = reset($result);

//    // Find and add uploaded files.
//    /** @var \Drupal\Core\Database\Query\Select $query */
//    $query = $this->select('upload', 'u')
//      ->distinct()
//      ->fields('u', ['fid', 'description', 'list'])
//      ->condition('u.nid', $row->getSourceProperty('nid'))
//      ->condition('u.vid', $row->getSourceProperty('vid'));
//    $files = $query->execute()->fetchAll();
//
//    if (count($files)) {
//      $row->setSourceProperty('attachments', $files);
//      $this->logError($row,
//        t('ErpTicket: @nid - Attached files to node', [
//          '@nid' => $row->getSourceProperty('nid'),
//        ]), MigrationInterface::MESSAGE_NOTICE);
//    }

    $row->setSourceProperty('title', substr($row->getSourceProperty('title'), 0, 128));

    return TRUE;
  }

}
