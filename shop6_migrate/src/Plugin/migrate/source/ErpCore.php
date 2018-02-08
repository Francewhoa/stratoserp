<?php

namespace Drupal\shop6_migrate\Plugin\migrate\source;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Plugin\migrate\source\d6\Node as MigrateNode;
use Drupal\migrate\Row;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\se_stock_item\Entity\StockItem;
use Drupal\shop6_migrate\Shop6MigrateUtilities;
use Drupal\user\Entity\User;
use Drupal\taxonomy\Entity\Term;

/**
 * Core Drupal6 erp class.
 */
class ErpCore extends MigrateNode {
  use Shop6MigrateUtilities;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, \Drupal\migrate\Plugin\MigrationInterface $migration, \Drupal\Core\State\StateInterface $state, \Drupal\Core\Entity\EntityManagerInterface $entity_manager, \Drupal\Core\Extension\ModuleHandler $module_handler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state, $entity_manager, $module_handler);

    if ($this->migration->id() == 'upgrade_d6_node_erp_goods_receipt') {
      \Drupal::service('event_dispatcher')
        ->addListener(MigrateEvents::POST_IMPORT,
          [$this, 'postImportEventRecorder']);
    }
  }

  /**
   * Retrieve the list of items for a content type and store them as paragraphs.
   *
   * @param \Drupal\migrate\Row $row
   *   The migrate row reference to work with.
   * @param \Drupal\migrate\Plugin\MigrateIdMapInterface $idMap
   *   The idMap from the migration.
   * @param string $data_table
   *   The data table from drupal6 erp to query for items.
   *
   * @throws \Exception setSourceProperty() might
   */
  public function setItems(Row $row, MigrateIdMapInterface $idMap, string $data_table) {
    $nid = $row->getSourceProperty('nid');

    // Connect to the old database to query the data table.
    $db = Database::getConnection('default', 'drupal_6');
    /** @var \Drupal\Core\Database\Query\Select $query */
    $query = $db->select($data_table, 'ecd');
    $query->fields('ecd');
    $query->condition('ecd.nid', $nid);
    $query->orderBy('ecd.line', 'ASC');

    $lines = $query->execute()->fetchAll();

    $items = [];
    $total = 0;
    foreach ($lines as $line) {
      /** @var \Drupal\paragraphs\Entity\Paragraph $paragraph */
      $paragraph = Paragraph::create(['type' => 'se_items']);
      // If no item nid, log an error
      if (empty($line->item_nid) || $line->item_nid == 0) {
        self::logError($row, $idMap,
          t('setItems: @nid - has zero item', [
            '@nid' => $nid,
          ]));
        $item = 0;
      }
      else {
        // If there is an nid, try and find it
        if (!$migrated_id = self::findNewId($line->item_nid, 'nid', 'upgrade_d6_node_erp_item')) {
          self::logError($row, $idMap,
            t('setItems: @nid - has deleted item', [
              '@nid' => $nid,
            ]));
          $item = 0;
        }
        else {
          // Found nid, try and match serial
          if (empty($line->serial) || preg_match('/TK - [0-9]+/', $line->serial)) {
            // Blank serial, make/use dummy serial entry
            $new_item = Node::load($migrated_id);
            $item = self::stockItemFindCreateVirtual($row, $this->idMap, $new_item->get('title')->value, $migrated_id);
          }
          else {
            if (!$stock_item = self::findItemBySerial($migrated_id, $line->serial)) {
              self::logError($row, $idMap,
                t('setItems: @nid - has zero item', [
                  '@nid' => $nid,
                ]));
              $item = 0;
            }
            else {
              $item = $stock_item->id();
            }
          }
        }
      }
      $paragraph->set('field_it_stock_item', ['target_id' => $item]);
      $paragraph->set('field_it_quantity', ['value' => $line->qty]);
      $paragraph->set('field_it_price', ['value' => $line->price]);
      $paragraph->set('field_it_description', [
        'value' => $line->extra,
        'format' => 'basic_html',
      ]);
      $paragraph->set('field_it_completed_date', ['value' => $line->completed_date]);
      $paragraph->save();
      $items[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];

      $total += ($line->qty * $line->price);
    }

    // Set the paragraph items
    $row->setSourceProperty('paragraph_items', $items);
    $row->setSourceProperty('total', $total);

  }

  /**
   * Create a virtual stock item for items that don't really track stock.
   *
   * @param $row
   * @param $idMap
   * @param $title
   * @param $item_nid
   *
   * @return int|null|string
   */
  public function stockItemFindCreateVirtual($row, $idMap, $title, $item_nid) {
    // Define a text 'serial' for virtual items
    $virtual_placeholder = 'VIRTUAL_ITEM_ONLY';
    if (!$stock_item = self::findItemBySerial($item_nid, $virtual_placeholder, TRUE)) {
      $stock_item = StockItem::create([
        'type' => 'se_stock_item',
        'user_id' => '1',
        'name' => $title,
        'field_si_serial' => $virtual_placeholder,
        'field_si_item_ref' => [['target_id' => $item_nid]],
        'field_si_virtual' => TRUE,
      ]);
      $stock_item->save();
      self::logError($row, $idMap,
        t('stockItemCreateVirtual: @nid - added virtual - @stock_id', [
          '@nid' => $item_nid,
          '@stock_id' => $stock_item->id(),
        ]));
    }
    else {
      self::logError($row, $idMap,
        t('stockItemCreateVirtual: @nid - found virtual - @stock_id', [
          '@nid' => $item_nid,
          '@stock_id' => $stock_item->id(),
        ]));
    }

    return $stock_item->id();
  }

  /**
   * Set the supplier id for the passed row.
   *
   * @param \Drupal\migrate\Row $row
   *   The migrate row reference to work with.
   * @param \Drupal\migrate\Plugin\MigrateIdMapInterface $idMap
   *   The idMap from the migration.
   * @param string $field
   *   The field to set.
   *
   * @return bool
   *   Whether a business was found or not.
   * @throws \Exception
   *   setSourceProperty() might
   */
  public function setSupplierRef(Row $row,
                                 MigrateIdMapInterface $idMap,
                                 string $field = 'supplier_ref') {

    if ($supplier_nid = $row->getSourceProperty('supplier_nid')) {
      if ($new_id = self::findNewId($supplier_nid, 'nid', 'upgrade_d6_node_erp_supplier')) {
        $row->setSourceProperty($field, $new_id);
        return TRUE;
      }
    }

    self::logError($row, $idMap,
      t('setBusinessRef: @nid - @title ignored', [
        '@nid'   => $row->getSourceProperty('nid'),
        '@title' => $row->getSourceProperty('title'),
      ]), MigrationInterface::MESSAGE_NOTICE);

    return FALSE;
  }

  /**
   * Set the customer id for the passed row.
   *
   * @param \Drupal\migrate\Row $row
   *   The migrate row reference to work with.
   * @param \Drupal\migrate\Plugin\MigrateIdMapInterface $idMap
   *   The idMap from the migration.
   * @param string $field
   *   The field to set.
   *
   * @return bool
   *   Whether a business was found or not.
   * @throws \Exception
   *   setSourceProperty() might
   */
  public function setBusinessRef(Row $row,
                                 MigrateIdMapInterface $idMap,
                                 string $field = 'business_ref') {

    if ($customer_nid = $row->getSourceProperty('customer_nid')) {
      $new_id = self::findNewId($customer_nid, 'nid', 'upgrade_d6_node_erp_customer');
      if ($new_id) {
        $row->setSourceProperty($field, $new_id);
        return TRUE;
      }
    }

    if ($customer_nid = $row->getSourceProperty('field_serp_cu_ref_nid')) {
      $new_id = self::findNewId($customer_nid, 'nid', 'upgrade_d6_node_erp_customer');
      if ($new_id) {
        $row->setSourceProperty($field, $new_id);
        return TRUE;
      }
    }

    // Have to try harder.
    $nid = $row->getSourceProperty('nid');
    if ($row->getSourceProperty('type') == 'book') {
      $db = Database::getConnection('default', 'drupal_6');
      /** @var \Drupal\Core\Database\Query\Select $query */
      $query = $db->select('erp_customer_link', 'ecl')
        ->fields('ecl', ['customer_nid'])
        ->condition('ecl.nid', $nid);
      $customer_nid = $query->execute()->fetchField();

      // If there was one, cool.
      if ($customer_nid) {
        $new_id = self::findNewId($customer_nid, 'nid', 'upgrade_d6_node_erp_customer');
        if ($new_id) {
          $row->setSourceProperty($field, $new_id);
          self::logError($row, $idMap,
            t('setBusinessRef: @nid - Customer link matched with @customer', [
              '@nid'      => $row->getSourceProperty('nid'),
              '@customer' => $new_id,
            ]));
          return TRUE;
        }
      }

      // Try loading the customer using the book title.
      $name = trim($row->getSourceProperty('title'));
      $result = \Drupal::entityQuery('node')
        ->condition('status', 1)
        ->condition('type', 'se_customer')
        ->condition('title', $name)
        ->execute();
      $customer_nid = reset($result);
      if ($customer_nid) {
        self::logError($row, $idMap,
          t('setBusinessRef: @nid - Name matched @customer for @name', [
            '@nid'      => $row->getSourceProperty('nid'),
            '@customer' => $customer_nid,
          ]));
        return TRUE;
      }

      // Ok, give up on it being a customer, try loading supplier by title.
      $result = \Drupal::entityQuery('node')
        ->condition('status', 1)
        ->condition('type', 'se_supplier')
        ->condition('title', $name)
        ->execute();
      $supplier_nid = reset($result);

      if ($supplier_nid) {
        $new_id = self::findNewId($supplier_nid, 'nid', 'upgrade_d6_node_erp_supplier');
        if ($new_id) {
          $row->setSourceProperty($field, $new_id);
          self::logError($row, $idMap,
            t('setBusinessRef: @nid - Name matched with @supplier', [
              '@nid'      => $row->getSourceProperty('nid'),
              '@supplier' => $new_id,
            ]));
          return TRUE;
        }
      }
    }

    self::logError($row, $this->idMap,
      t('setBusinessRef: @nid - @title ignored', [
        '@nid'   => $row->getSourceProperty('nid'),
        '@title' => $row->getSourceProperty('title'),
      ]), MigrationInterface::MESSAGE_NOTICE);

    return FALSE;
  }

  /**
   * Get the list of users that are assigned to a job and set them.
   *
   * @param \Drupal\migrate\Row $row
   *   The migrate row.
   *
   * @throws \Exception
   *   setSourceProperty() might
   */
  public function setOwnerRef(Row $row) {
    static $user_map = [];

    $nid = $row->getSourceProperty('nid');

    $db = Database::getConnection('default', 'drupal_6');
    /** @var \Drupal\Core\Database\Query\Select $query */
    $query = $db->select('content_field_serp_jo_owner', 'cfsjo')
      ->fields('cfsjo')
      ->condition('cfsjo.nid', $nid);
    $owners = $query->execute()->fetchAll();

    $users = [];
    foreach ($owners as $user_object) {
      if (!isset($user_map[$user_object->field_serp_jo_owner_uid])) {
        $user_map[$user_object->field_serp_jo_owner_uid] = User::load($user_object->field_serp_jo_owner_uid);
      }
      $users[] = $user_map[$user_object->field_serp_jo_owner_uid];
    }
    $row->setSourceProperty('owner_ref', $users);
  }

  /**
   * Process the website field to ensure its valid and can be stored.
   *
   * @param \Drupal\migrate\Row $row
   *   The migrate row reference to work with.
   * @param \Drupal\migrate\Plugin\MigrateIdMapInterface $idMap
   *   The idMap from the migration.
   * @param string $field
   *   The field name from the row that contains the homepage.
   *
   * @throws \Exception
   *   setSourceProperty() might
   */
  public function setBusinessHomepage(Row $row,
                                      MigrateIdMapInterface $idMap,
                                      string $field) {
    $message = NULL;
    $status = NULL;

    $homepage = trim($row->getSourceProperty($field));
    if (!empty($homepage)) {
      // Try and fix up common issues.
      if (substr($homepage, 0, 3) != 'http' && substr($homepage, 0, 3) == 'www') {
        $homepage = 'http://' . $homepage;
      }
      try {
        $row->setSourceProperty($field . '_uri', Url::fromUri($homepage)
          ->toString());
      }
      catch (\InvalidArgumentException $e) {
        self::logError($row, $idMap,
          t('setBusinessHomepage: @nid - @code invalid url, ignored', [
            '@nid'  => $row->getSourceProperty('nid'),
            '@code' => $homepage,
          ]), MigrationInterface::MESSAGE_NOTICE);
      }
    }
  }

  /**
   * Set all the taxonomy terms for an item.
   *
   * @param \Drupal\migrate\Row $row
   *   The migrate row.
   *
   * @throws \Exception
   *   setSourceProperty() might in setTaxonomyTermByName()
   */
  public function setItemTaxonomyTerms(Row $row) {
    $current_nid = $row->getSourceProperty('nid');
    $current_vid = $row->getSourceProperty('vid');

    $db = Database::getConnection('default', 'drupal_6');
    $query = $db->select('term_node', 'tn');
    $query->fields('tn');
    $query->leftJoin('term_data', 'td', 'td.tid = tn.tid');
    $query->fields('td');
    $query->condition('tn.nid', $current_nid);
    $query->condition('tn.vid', $current_vid);

    $terms = $query->execute()->fetchAll();

    foreach ($terms as $term) {
      switch ($term->vid) {
        case 2:
          $destination_vocabulary = 'se_product_type';
          $destination_field = 'field_it_product_type_ref';
          self::setTaxonomyTermByName($row, $term->name, $destination_vocabulary, $destination_field);
          break;

        case 10:
          $destination_vocabulary = 'se_manufacturer';
          $destination_field = 'field_it_manufacturer_ref';
          self::setTaxonomyTermByName($row, $term->name, $destination_vocabulary, $destination_field);
          break;

        case 12:
          $destination_vocabulary = 'se_sale_category';
          $destination_field = 'field_it_sale_category_ref';
          self::setTaxonomyTermByName($row, $term->name, $destination_vocabulary, $destination_field);
          break;

      }
    }
  }

  /**
   * Set taxonomy term.
   *
   * @param \Drupal\migrate\Row $row
   *   The migrate row.
   * @param string $source_field
   *   The field in the source record to retrieve.
   * @param int $source_vocabulary
   *   The source vocabulary id.
   * @param string $destination_vocabulary
   *   The destination vocabulary.
   * @param string $destination_field
   *   The Destination field.
   *
   * @throws \Exception
   *   setSourceProperty() might in setTaxonomyTermByName()
   */
  public function setTaxonomyTermByRef(Row $row,
                                       string $source_field,
                                       int $source_vocabulary,
                                       string $destination_vocabulary,
                                       string $destination_field) {
    $term_id = $row->getSourceProperty($source_field);

    if (isset($term_id)) {
      $term_name = $this->getTermNameById($term_id, $source_vocabulary);

      if (!empty($term_name)) {
        self::setTaxonomyTermByName($row, $term_name, $destination_vocabulary, $destination_field);
      }
    }
  }

  /**
   * Lookup a taxonomy term and set the corresponding field.
   *
   * @param \Drupal\migrate\Row $row
   *   The migrate row reference to work with.
   * @param string $name
   *   The field name from the row.
   * @param string $vocabulary
   *   The taxonomy vocabulary to load the term from.
   * @param string $destination
   *   The field name to be set.
   *
   * @throws \Exception
   *   setSourceProperty() might.
   */
  public function setTaxonomyTermByName(Row $row,
                                        string $name,
                                        string $vocabulary,
                                        string $destination) {
    static $static_term_id_cache = [];

    if (!isset($static_term_id_cache[$vocabulary][$name])) {
      $terms = taxonomy_term_load_multiple_by_name($name, $vocabulary);

      if (!$terms) {
        Term::create([
          'parent' => [],
          'name'   => $name,
          'vid'    => $vocabulary,
        ])->save();

        // Now retrieve the term again.
        $terms = taxonomy_term_load_multiple_by_name($name, $vocabulary);
      }

      $term = reset($terms);
      $static_term_id_cache[$vocabulary][$name] = $term->id();
      $row->setSourceProperty($destination, $term->id());
    }
    else {
      $row->setSourceProperty($destination, $static_term_id_cache[$vocabulary][$name]);
    }
  }

  /**
   * Lookup a taxonomy term from the d6 database by term id and vocab id.
   *
   * @param int $term_id
   *   The d6 taxonomy term id.
   * @param int $old_vid
   *   The d6 taxonomy term vid.
   *
   * @return null|string
   *   Return the taxonomy term.
   */
  public function getTermNameById(int $term_id,
                                  int $old_vid) {
    static $static_term_id_name_cache = [];

    if (!isset($term_id)) {
      return NULL;
    }

    if (!isset($static_term_id_name_cache[$old_vid][$term_id])) {
      $db = Database::getConnection('default', 'drupal_6');
      /** @var \Drupal\Core\Database\Query\Select $query */
      $query = $db->select('term_data', 'td');
      $query->fields('td', [
        'name',
      ]);
      $query->condition('td.tid', $term_id);
      $query->condition('td.vid', $old_vid);
      $d6_term = $query->execute()->fetch();
      if ($d6_term) {
        $static_term_id_name_cache[$old_vid][$term_id] = $d6_term->name;
      }
      else {
        return NULL;
      }
    }

    return $static_term_id_name_cache[$old_vid][$term_id];
  }

  /**
   * As this is the last migration that will affect the stock items, we can now
   * do a run through and update their data one final time.
   *
   * @param \Drupal\migrate\Event\MigrateImportEvent $event
   * @param $name
   *
   * @return bool
   */
  public function postImportEventRecorder(MigrateImportEvent $event, $name) {
    $migration = $event->getMigration();

    // Only run this when we're up to the other comment section, which is last.
    if ($migration->id() != 'upgrade_d6_node_erp_goods_receipt') {
      return TRUE;
    }
    else {
      if (\Drupal::state()->get('postImportRun')) {
        return TRUE;
      }
    }

    // Set the state so we don't run more than once.
    \Drupal::state()->set('postImportRun', TRUE);

    // Connect to the old database to query the stock table for items with a serial number.
    $db = Database::getConnection('default', 'drupal_6');
    /** @var \Drupal\Core\Database\Query\Select $query */
    $query = $db->select('erp_stock', 'es');
    $query->fields('es');
    $query->condition('es.serial', '', '<>');
    $query->isNotNull('es.serial');
    $results = $query->execute()->fetchAll();

    echo "Assigning Goods Receive, PO and Invoices references to items.\n";
    echo count($results) . " to process, this may take some time.\n";

    // Loop through the d6 stock items, loading and updating the d8 items
    $count = 0;
    $updated = 0;
    foreach ($results as $d6_stock_item) {
      $stock_item_ref = self::findNewId($d6_stock_item->stock_nid, 'nid', 'upgrade_d6_node_erp_item');
      $serial = $d6_stock_item->serial;

      $query = \Drupal::entityQuery('se_stock_item');
      $query->condition('field_si_item_ref', $stock_item_ref);
      $query->condition('field_si_serial', $serial);
      $result = $query->execute();

      // If there wasn't a new stock item to match, move along.
      if (!$stock_item_id = reset($result)) {
        continue;
      }

      // load the new stock item
      $se_stock_item = StockItem::load($stock_item_id);
      if ($se_stock_item) {
        $update_required = FALSE;
        $gr_nid = $d6_stock_item->receipt_nid;
        $po_nid = $d6_stock_item->purchase_order_nid;

        if (!isset($se_stock_item->get('field_si_goods_receipt_ref')->target_id) && !empty($gr_nid)) {
          if ($gr_ref = self::findNewId($gr_nid, 'nid', 'upgrade_d6_node_erp_goods_receipt')) {
            $se_stock_item->set('field_si_goods_receipt_ref', $gr_ref);
            $update_required = TRUE;
          }
        }

        if (!isset($se_stock_item->get('field_si_purchase_order_ref')->target_id) && !empty($po_nid)) {
          if ($po_ref = self::findNewId($po_nid, 'nid', 'upgrade_d6_node_erp_purchase_order')) {
            $se_stock_item->set('field_si_purchase_order_ref', $po_ref);
            $update_required = TRUE;
          }
        }

        if (!isset($se_stock_item->get('field_si_invoice_ref')->target_id)) {
          if ($invoice_ref = self::findInvoiceBySerial($d6_stock_item->stock_nid, $serial)) {
            $se_stock_item->set('field_si_invoice_ref', $invoice_ref);
            $update_required = TRUE;
          }
        }

        if ($update_required) {
          $se_stock_item->save();
          $updated++;
        }
      }

      if (++$count % 1000 == 0) {
        echo "Processed $count stock_items, $updated updated\n";
      }
    }
  }


}
