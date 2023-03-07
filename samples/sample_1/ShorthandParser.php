<?php

namespace Drupal\some_module\Something;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\search_api\Entity\Index;

/**
 * A parser to convert shorthand query to search_api query and vice-versa.
 */
class ShorthandParser {

  /**
   * AND and OR operators.
   */
  public const OP_AND = ';';
  public const OP_OR = ',';

  /**
   * Supported operators.
   */
  public const OP_FULL_TEXT = '=match=';
  public const OP_EQ = '==';
  public const OP_NOT_EQ = '!=';
  public const OP_IN = '=in=';
  public const OP_NOT_IN = '=out=';
  public const OP_LT = '=lt=';
  public const OP_LE = '=le=';
  public const OP_GT = '=gt=';
  public const OP_GE = '=ge=';

  /**
   * Brackets - used for grouping.
   */
  public const OP_OBR = '(';
  public const OP_CBR = ')';

  /**
   * Operators and their corresponding symbol.
   */
  public const OPERATORS = [
    self::OP_AND => 'AND',
    self::OP_OR => 'OR',
    self::OP_IN => 'IN',
    self::OP_NOT_IN => 'NOT IN',
    self::OP_EQ => '=',
    self::OP_NOT_EQ => '!=',
    self::OP_LT => '<',
    self::OP_LE => '<=',
    self::OP_GT => '>',
    self::OP_GE => '>=',
    self::OP_FULL_TEXT => 'MATCH',
  ];

  /**
   * The entity type repository object.
   *
   * @var Drupal\Core\Entity\EntityTypeRepositoryInterface
   */
  protected $entityTypeRepo;

  /**
   * The entity type manager object.
   *
   * @var Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * The list of all fields that are indexed.
   *
   * @var array
   */
  protected $indexedFields = [];

  /**
   * Construct the ShorthandParser Object.
   *
   * @param Drupal\Core\Entity\EntityTypeRepositoryInterface $entity_type_repository
   *   The entity type repository object.
   * @param Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity type manager object.
   */
  public function __construct(EntityTypeRepositoryInterface $entity_type_repository, EntityTypeManagerInterface $entity_manager) {
    $this->entityTypeRepo = $entity_type_repository;
    $this->entityManager = $entity_manager;
    $this->loadIndexedFields();
  }

  /**
   * Load the search index and populate the list of all indexed fields.
   */
  private function loadIndexedFields() {
    $storage = $this->entityManager->getStorage($this->entityTypeRepo->getEntityTypeFromClass(Index::class));
    // Get the search api index name here from config.
    // This is to be able to use short field names.
    $index = $storage->load("SEARCH_INDEX_NAME");
    $this->indexedFields = $index->getFields();
  }

  /**
   * Get the list of all indexed fields.
   *
   * @return array
   *   The list of all indexed fields.
   */
  protected function getIndexedFields() {
    return $this->indexedFields;
  }

  /**
   * Parse the given shorthand query string and build conditions array.
   *
   * @param string $q
   *   The shorthand query string.
   *
   * @return array[]
   *   Conditions array built from the shorthand query.
   */
  public function parse($q) {
    $params = [];
    $this->expandQuery($q, $params);
    return ['filter' => $params];
  }

  /**
   * Helper function to break the query and format it into AND and OR groups.
   *
   * @param string $q
   *   The shorthand query string.
   *
   * @return array
   *   An array containing AND and OR condition groups based on given query.
   */
  private function clean($q) {
    $and = $this->explodeQuery($q, self::OP_AND);
    $or = $this->explodeQuery($q, self::OP_OR);

    $and_g = $this->buildGroup($and, self::OP_AND);
    $or_g = $this->buildGroup($or, self::OP_OR);

    // If no condition groups found, default to an AND group.
    if (empty($and_g) && empty($or_g) && $and === $or) {
      $and_g = $and;
    }
    // For a single condition, default to an AND group.
    if ($and_g === $or_g && $and === $or) {
      $or_g = [];
    }

    return [
      'AND' => $and_g,
      'OR' => $or_g,
    ];
  }

  /**
   * Helper function to clean the AND and OR group arrays from explodeQuery().
   *
   * @param array $data
   *   Array of items from explodeQuery() function.
   * @param string $delim
   *   The operator (;/,).
   *
   * @return array
   *   A cleaned up array.
   */
  private function buildGroup(array $data, string $delim) {
    $other_delim = self::OP_AND;
    if ($delim === self::OP_AND) {
      $other_delim = self::OP_OR;
    }
    $ret = [];
    for ($i = 0; $i < count($data); $i++) {
      $item = $data[$i];
      if ($i == 0 && isset($data[$i + 1]) && str_starts_with($data[$i + 1], $other_delim)) {
        continue;
      }
      if (str_ends_with($item, self::OP_IN) || str_ends_with($item, self::OP_NOT_IN)) {
        continue;
      }
      if (str_starts_with($item, $other_delim)) {
        continue;
      }
      if (isset($data[$i - 1]) && $data[$i - 1] === $other_delim) {
        continue;
      }
      if (str_starts_with($item, self::OP_OBR) && str_ends_with($item, self::OP_CBR)) {
        if ((isset($data[$i - 1]) && $data[$i - 1] !== $other_delim)) {
          $ret[] = $item;
        }
        elseif ((isset($data[$i + 1]) && !str_starts_with($data[$i + 1], $other_delim))) {
          $ret[] = $item;
        }
        continue;
      }

      $ret[] = $item;
    }

    return $ret;
  }

  /**
   * Helper function to break given query to AND and OR groups and process them.
   *
   * @param string $q
   *   The shorthand query string.
   * @param array $params
   *   The parameters built from the query string incrementally.
   * @param int $index
   *   An index to keep track of different condition groups.
   */
  private function expandQuery(string $q, array &$params, int $index = 1) {
    $group_arr = $this->clean($q);
    if (!empty($group_arr['AND'])) {
      $this->processGroup($group_arr['AND'], $params, $index, 'group-' . ($index - 1), self::OP_AND);
      $index++;
    }
    if (!empty($group_arr['OR'])) {
      $this->processGroup($group_arr['OR'], $params, $index, 'group-' . ($index - 1), self::OP_OR);
    }
  }

  /**
   * Helper function to process individual condition group query strings.
   *
   * @param array $data
   *   The condition group array.
   * @param array $params
   *   The parameters built from the query string incrementally.
   * @param int $index
   *   An index to keep track of different condition groups.
   * @param string $group
   *   The name of the current condition group being processed.
   * @param string $operator
   *   The operator/conjunction of the current condition group being processed.
   */
  private function processGroup(array $data, array &$params, int $index, string $group, string $operator) {
    // Increment group index if group exists already due to previous runs.
    while (isset($params['group-' . ($index)])) {
      $index++;
    }
    // Add condition group.
    $params['group-' . ($index)]['group']['conjunction'] = self::OPERATORS[$operator];
    if ($group === 'group-0') {
      $group = '@root';
    }
    $params['group-' . ($index)]['group']['memberOf'] = $group;

    // Add filters to condition group.
    for ($i = 0; $i < count($data); $i++) {
      $item = $data[$i];

      // Handle sub group.
      // These are the conditions withing () braces.
      if (str_starts_with($item, self::OP_OBR) && str_ends_with($item, self::OP_CBR)) {
        $trimmed = substr($item, 1, strlen($item) - 2);
        $this->expandQuery($trimmed, $params, $index + 1);
        continue;
      }

      // Handle different conditions using different operators.
      foreach (self::OPERATORS as $operator => $op_sym) {
        if (stripos($item, $operator) !== FALSE) {
          $params['group-' . $index . '-filter-' . $i] = $this->getFieldCondition($item, $operator, $index);
        }
      }
    }
  }

  /**
   * Helper function to convert a single condition string to condition array.
   *
   * @param string $cond_str
   *   The single condition string.
   * @param string $operator
   *   The operator for this condition.
   * @param int $group_index
   *   The group index saying which condition group this condition belongs to.
   *
   * @return array
   *   A formatted condition array.
   */
  private function getFieldCondition(string $cond_str, string $operator, int $group_index) {
    $res = [];
    $field = '';
    $value = '';

    $parts = explode($operator, $cond_str);
    if (isset($parts[0])) {
      // Handle using short field names without the 'field_' prefix.
      $field = $parts[0];
      if (!isset($this->indexedFields[$field]) && isset($this->indexedFields['field_' . $field])) {
        $field = 'field_' . $field;
      }
    }
    if (isset($parts[1])) {
      // Handle IN operator values where the value would be an array.
      $value = $parts[1];
      if ($operator == self::OP_IN || $operator == self::OP_NOT_IN) {
        $value = trim($parts[1], '()');
        $value = explode(',', $value);
      }
    }

    $res['condition']['path'] = $field;
    $res['condition']['value'] = $value;
    $res['condition']['operator'] = self::OPERATORS[$operator];
    $res['condition']['memberOf'] = 'group-' . $group_index;

    return $res;
  }

  /**
   * Helper function to break the query into meaningful parts.
   *
   * @param string $query_str
   *   The shorthand query string.
   * @param string $delimiter
   *   The delimiter used to break the query (';' or ',').
   *
   * @return array
   *   Array of elements broken by the given delimiter.
   */
  private function explodeQuery(string $query_str, string $delimiter) {
    // Break the query on () and delimiter.
    preg_match_all("/\((?:[^()]|(?R))+\)|'[^']*'|[^()$delimiter]+/", $query_str, $matches);
    if (!$matches[0]) {
      return [];
    }

    $other_delim = self::OP_AND;
    if ($delimiter === self::OP_AND) {
      $other_delim = self::OP_OR;
    }

    $items = $matches[0];
    $ret = [];

    for ($i = 0; $i < count($items); $i++) {
      $item = $items[$i];
      // Break the query at the other delim too,
      // but in this case keep the delim char in the query.
      // So a;b would become 'a', ';b'.
      if ((!str_starts_with($item, self::OP_OBR) || !str_ends_with($item, self::OP_CBR)) &&
        strpos($item, $other_delim) !== FALSE) {
        $last = '';
        if (str_ends_with($item, self::OP_IN) || str_ends_with($item, self::OP_NOT_IN)) {
          $last = $items[$i + 1];
          $i++;
        }
        $starts_with_delim = str_starts_with($item, $other_delim);
        $others = explode($other_delim, $item);
        for ($in = 0; $in < count($others); $in++) {
          if ($in == 0 && !$starts_with_delim) {
            $ret[] = $others[$in];
            continue;
          }
          if ($in == count($others) - 1) {
            $ret[] = $other_delim . $others[$in] . $last;
            continue;
          }
          $ret[] = $other_delim . $others[$in];
        }
        continue;
      }
      // Handle IN operator case.
      // IN operator has (data1,data2) and this would be split due to the ().
      // Here we add it back together.
      if (str_ends_with($item, self::OP_IN) || str_ends_with($item, self::OP_NOT_IN)) {
        $ret[] = $items[$i] . $items[$i + 1];
        $i++;
        continue;
      }
      $ret[] = $items[$i];
    }

    return $ret;
  }

  /**
   * Function to convert original parameter array to shorthand query string.
   *
   * @param array $params
   *   The parameter array containing conditions and condition groups data.
   * @param string $res
   *   The result shorthand query string.
   * @param array $groups
   *   The list of condition groups already parsed/handled.
   * @param string $curr_group
   *   The current condition group to be handled in this call.
   */
  public function convertToShorthand(array $params, string &$res, array &$groups = ['@root'], string $curr_group = '@root') {
    // Default op is AND when @root is used.
    $op = self::OP_AND;
    if (isset($params[$curr_group]['group']['conjunction'])) {
      $op = array_search($params[$curr_group]['group']['conjunction'], self::OPERATORS);
    }
    foreach ($params as $key => $param) {
      if (isset($param['group']) && !in_array($key, $groups) && $params[$key]['group']['memberOf'] == $curr_group) {
        $res .= '(';
        array_unshift($groups, $key);
        $this->convertToShorthand($params, $res, $groups, $key);
        $res .= ')';
        $res .= $op;
      }
      elseif ($param['condition']['memberOf'] == $curr_group) {
        // Handle IN operator in value.
        $value = $param['condition']['value'];
        if (is_array($value)) {
          $value = '(' . implode(',', $value) . ')';
        }
        // Handle field name and shorten it if applicable.
        $field = $param['condition']['path'];
        if (isset($this->indexedFields[$field]) && str_starts_with($field, 'field_')) {
          $field = substr($field, 6);
        }
        // Add data to shorthand query.
        if (!str_ends_with($res, $op) && !str_ends_with($res, self::OP_OBR) && strlen($res) > 0) {
          $res .= $op;
        }
        $res .= $field . array_search($param['condition']['operator'], self::OPERATORS) . $value;
        $res .= $op;
      }
    }
    // Remove last operator.
    if (str_ends_with($res, self::OP_AND) || str_ends_with($res, self::OP_OR)) {
      $res = substr($res, 0, strlen($res) - 1);
    }
  }

}
