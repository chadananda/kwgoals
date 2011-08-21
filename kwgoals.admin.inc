<?php

//  $Id$

/*
 *
 * @file
 *
 */
 

define('KWGOALS_DAMPEN_TOP', 50); // % to decrease potential of serp1 links - the more we do, the more focus other links get
define('KWGOALS_COMPETITION_WEIGHT', 50); // % how much of our weight should be affected by Google's compeition ratio

if (!function_exists('db_fetch_array')) {
  function db_fetch_array($query) {
    if (is_object($query)) return $query->fetchAssoc();
  }
} 
if (!function_exists('db_result')) {
  function db_result($query) {
    if (is_object($query)) return $query->fetchField();
  }
}


/**
 * @todo Please document this function.
 * @see http://drupal.org/node/1354
 */
function kwgoals_update_blink_goals_cronjob() {
  if (!module_exists('blink')) return;
 
  $source = "best-google-hits";
  // set the blink keyword goals group so that they are not editable in the blink settings form
  variable_set("{$source}_blink_readonly", TRUE); 
  variable_set("{$source}_blink_weight", 10);
  variable_set("{$source}_blink_description", t('("kwgoals" module) These are high potential traffic-generating keywords found in the referrer data of actual Google search hits. The single page with the best SERP "wins" the keyword so each keyword is assigned only once. This way we do not compete internally for the same keyword. '));
  
  // get list of goals from blink
  $goals = blink_get_keyword_goals($source); // returns full record array 
  // build index by keyword, since our keywords are unique
  if ($goals) foreach ($goals as $row) $indexed[$row['kw']] = $row;  
  
  // build desired list of keywords with recognized potential
  $query = db_query("SELECT phrase, nid, weight FROM {kwgoals_kw} WHERE weight > 0 AND nid > 0"); 
  while ($kw = db_fetch_array($query)) $kwgoals[$kw['phrase']] = $kw;  
  
  $query = db_select('kwgoals_kw', 'kw')  
     ->fields('kw', array('phrase', 'nid', 'serp')) 
     ->condition('hit.date', strtotime("-1 month"), '>') 
     ->condition('kw.all_traffic', 0) 
     ->condition('kw.serp', 1, '>');
  $query->join('kwgoals_hits', 'hit', 'hit.kwid = kw.kwid');  
  $query->groupBy('hit.kwid'); 
  $query->orderBy('traffic', 'DESC');  
  $query->addExpression('COUNT(hit.kwid)', 'traffic');
  $result =  $query->execute();    
     
  while ($kw = db_fetch_array($result)) if (!isset($kwgoals[$kw['phrase']])) {
    $kw['weight'] = _kwgoals_estimate_weight_from_traffic($kw['serp'], $kw['traffic']);
    if ($kw['weight'] > 5) { // remove riff-raff
      if ($kw['weight'] > 50) $kw['weight'] = 50; 
      $kwgoals[$kw['phrase']] = $kw;
    }
  }
  //drupal_set_message("Kwgoals found ". count($kwgoals) ." keywords that should be blink goals");
  // loop through and update or add, then remove from list
  if (isset($kwgoals)) foreach ($kwgoals as $kw) {
    $url = url('node/' . $kw['nid'], array('absolute' => TRUE));
    $phrase = $kw['phrase'];
    $weight = $kw['weight'];
    if ($goal = $indexed[$phrase]) { // update or ignore
      if (($goal['url'] != $url) || ($goal['weight'] != $weight)) {
        $goal['url'] = $url;
        $goal['weight'] = $weight;
        blink_update_keyword_goal($goal, $source);
        //drupal_set_message("Updated blink keyword '{$phrase}'");
      } // else drupal_set_message("Keyword already a blink goal: '{$phrase}'");
      unset($indexed[$phrase]);
    }
    else { // add new
      blink_add_keyword_goal($phrase, $url, $weight, $source);
      //drupal_set_message("Added blink keyword '{$phrase}'");
      unset($indexed[$phrase]);
    }
  }
  // delete any remaining items
  if (isset($indexed)) {
    foreach ($indexed as $goal) {
      //drupal_set_message("Deleted blink keyword '{$goal['kw']}'");
      blink_delete_keyword_goal($goal, $source);
    }
  }
}


// provide a tag cloud for node blocks, randomized and sized to potential
// same block should be universal when not on node page
/**
 * Implements hook_block_info().
 */
function kwgoals_block_info() {
  $block[0]['info'] = t('Page Keywords');
  $block[1]['info'] = t('Site Keywords');
  return $block;
}

/**
 * Implements hook_block_view().
 */
function kwgoals_block_view($delta) {
  // TODO Rename block deltas (e.g. delta-0) to readable strings.
  switch ($delta) {
    case 'delta-0':
      $block['subject'] = t('Page Keywords');
      $block['content'] = _kwgoals_keyword_cloud(TRUE, 100, 'page_search');
      break;
    case 'delta-1':
      $block['subject'] = t('Site Keywords');
      $block['content'] = _kwgoals_keyword_cloud(FALSE, 100, 'site_search');
      break;
  }
  return $block;
}

/**
 * @todo Please document this function.
 * @see http://drupal.org/node/1354
 */
function kwgoals_block_OLD($op = 'list', $delta = 0) {
  // TODO Remaining code in this function needs to be moved to the appropriate new hook function.
  $block = array();
}

function _kwgoals_keyword_cloud($page_only = TRUE, $max = 100, $class = 'kwgoals') {
  if ($page_only && (int) arg(1)) {
    $this_nid = (int) arg(1);
  }
  // keywords with high weight for which we have content
  if ($this_nid) {
    $query = db_query_range("SELECT phrase, weight, nid, serp, competition FROM {kwgoals_kw} WHERE nid = :nid ORDER BY WEIGHT DESC", 0, $max, array(':nid' => $this_nid));
  }
  else {
    $query = db_query_range("SELECT phrase, weight, nid, serp FROM {kwgoals_kw} WHERE nid > 0 AND serp > 1 ORDER BY WEIGHT DESC", 0, $max);
  }
  // build link list with weight based on serp
  while ($r = db_fetch_array($query)) {
    $links[] = l($r['phrase'], 'node/' . $r['nid'], array('attributes' => array('class' => 'pos_' . $r['serp'])));
  }
  if ($links) {
    return "<div class='{$class}'>" . implode(', ', $links) . "</div>";
  }
}

// settings form
//================

/**
 * @todo Please document this function.
 * @see http://drupal.org/node/1354
 */
function kwgoals_add_keywords_form($form, &$form_state) {
  
 // kwgoals_update_blink_goals_cronjob(); // for testing  
  
  $form['kwgoals_keywords']  = array(
    '#type' => 'fieldset',
    '#title' => t('Keyword Goal Phrases'),
    '#description' => '<h3>Paste these keywords into Google\'s  ' .
      l(t('External Keywords Tool'), 'http://bit.ly/9FqW8F', array('attributes' => array('target' => '_blank'))) .
        ' then upload resulting CSV file. </h3>',
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );
  $tracked_with_traffic = _kwgoals_get_tracked_keywords(TRUE);
  $tracked_no_traffic =  _kwgoals_get_tracked_keywords(FALSE);
  $tracked_all = array_merge($tracked_with_traffic, $tracked_no_traffic);
  
  if (count($tracked_all)) {
    $title = number_format(count($tracked_all)) . " " . t("Google Keywords");
    $fields = array(
      '#data' => array(
        'competitive' => count($tracked_with_traffic) / count($tracked_all),
        'obscure' => count($tracked_no_traffic) / count($tracked_all),
      ),
      '#legends' => array(
        "Competitive (" . count($tracked_with_traffic) . ")",
        "Obscure (" . count($tracked_no_traffic) . ")",
      ),
    );
    $chart_rendered = _kwgoals_pie_chart($title, $fields);
  }
 
  $form['kwgoals_keywords']['koc_keywords_cluster'] = array(
    '#type' => 'textarea',
    '#title' => t('Website keyword cluster @all keywords @competitive competitive', array(
      '@all' => count($tracked_all), 
      '@competitive' => count($tracked_with_traffic),
    )),
    '#attributes' => array('readonly' => "readonly"),
    '#default_value' => implode("\n", $tracked_all),
    '#description' => t('Main keyword targets for this website (read only).'),
    '#prefix' => "<div style='float:right; margin-top:0px;'>{$chart_rendered}</div><div style='margin-right:310px;'>",
    '#suffix' => "</div>",
  );
  $form['kwgoals_keywords']['kwgoals_csv_upload'] = array(
    '#type' => 'file',
    '#title' => t('Upload CSV File'),
    '#description' => t('After generating a CSV file with the Google External Keywords tool, upload it here.'),
    '#attributes'  => array('accept' => 'text/csv'),
    '#size' => 20,
  );
  $form['kwgoals_keywords']['kwgoals_submit'] = array(
    '#type' => 'submit',
    '#value' => t('Upload CSV'),
    '#suffix' => '<hr/>',
  );

  $chart_rendered = _kwgoals_chart_tracked_keywords();
  $form['kwgoals_keywords']['kwgoals_keywords_suggested'] = array(
    '#type' => 'textarea',
    '#title' => t('Top Google hit keywords to consider adding'),
    '#attributes' => array('readonly' => "readonly"),
    '#default_value' => implode("\n", _kwgoals_get_suggested_keywords(100)),
    '#description' => t('To add these, paste them into the External Keyword Tool and upload the resulting CSV.'),
    '#prefix' => "<div style='float:right; margin-top:0px;'>{$chart_rendered}</div><div style='margin-right:310px;'>",
    '#suffix' => "</div>",
  );

  $form['#attributes']['enctype'] = 'multipart/form-data';
  $form['kwgoals_keywords']['kwgoals_example_import_image'] = array(
    '#markup' => '<br/><img src="http://bit.ly/hWAbqW" style="width:95%; margin:5px; padding:5px; border:1px solid silver;" /><br/>',
    '#weight' => 10,
  );

  return $form;
}



/**
 * @todo Please document this function.
 * @see http://drupal.org/node/1354
 */
function kwgoals_add_keywords_form_validate($form, &$form_state) {
  $validators = array('file_validate_extensions' => array('csv')); 
  $file = file_save_upload('kwgoals_csv_upload', $validators);
  
  //drupal_set_message("<pre>".print_r($file, true)."</pre>");
  
  if (!drupal_realpath($file->destination)) {
    form_set_error('kwgoals_csv_upload', t("CSV file is required"));
  }

  // checking the data first for all fields then for keyword type "exact"
  include_once('csv_reader.class.php');
  // Keyword,Competition,Global Monthly Searches,Local Monthly Searches
  $slice = array('Keyword', 'Competition', 'Global Monthly Searches');
  if (!($data = csv_reader::file_to_array(drupal_realpath($file->destination), $slice))) {
    drupal_set_message(t('No data returned from this CSV file. Are you sure it\'s the right format (Just plain CSV)?'), 'warning');
    return;
  }

  $row1 = array_shift($data);
  $csv_keys = array_keys($row1);
  foreach ($slice as $key) {
    if (!in_array($key, $csv_keys)) {
      form_set_error('kwgoals_csv_upload', t("CSV file is missing fields."));
      return;
    }
  }
  $ok = ((substr($row1['Keyword'], 0, 1) == '[') && (substr($row1['Keyword'], 0, 1)));
  if (!$ok) {
    form_set_error('kwgoals_csv_upload', t("CSV Keywords must be 'exact' match. Please re-download CSV file."));
    return;
  }
  else {
    $form_state['values']['kwgoals_csv_upload'] = drupal_realpath($file->destination);
  }
}
/**
 * @todo Please document this function.
 * @see http://drupal.org/node/1354
 */
function kwgoals_add_keywords_form_submit($form, &$form_state) {
  $csv_file = trim($form_state['values']['kwgoals_csv_upload']);
  if (!file_exists($csv_file)) {
    drupal_set_message(t("File not found: @file", array('@file' => $csv_file)), 'warning');
    return;
  }
  include_once('csv_reader.class.php');
  $slice = array('Keyword', 'Competition', 'Global Monthly Searches');
  $data = csv_reader::file_to_array($csv_file, $slice);
  // loop through and add or update each keyword
  if (is_array($data)) foreach ($data as $kw) {
      // add or update record for each keyword
      $keyword = trim(str_replace(array(",", ';', "'", '"', '[', ']'), "",   strtolower($kw['Keyword'])));
      if (!$keyword || substr($keyword, 1, 2) == '==') {
        continue;
      }
      $kwgoals_kw = db_query("SELECT kwid,nid,serp FROM {kwgoals_kw} WHERE phrase = :phrase", array(':phrase' => $keyword))->fetchAssoc();
      $kwgoals_kw['serp'] = isset($kwgoals_kw['serp']) ? $kwgoals_kw['serp'] : 0;
      $kwgoals_kw['phrase'] = $keyword;
      $kwgoals_kw['competition'] = round((int) ($kw['Competition'] * 100));
      $kwgoals_kw['all_traffic'] = (int) $kw['Global Monthly Searches'];
      $kwgoals_kw['potential'] = _kwgoals_estimate_potential_growth($kwgoals_kw['serp'], $kwgoals_kw['all_traffic']);
      $kwgoals_kw['weight'] = _kwgoals_estimate_link_weight($kwgoals_kw['potential'], $kwgoals_kw['competition']);
      $kwgoals_kw['updated'] = (int) strtotime('now');
      
//drupal_set_message("<pre>".print_r($kwgoals_kw, true)."</pre>");

      drupal_write_record('kwgoals_kw', $kwgoals_kw, isset($kwgoals_kw['kwid']) ? array('kwid') : array());
      $updated++;
  }
  if ($updated) drupal_set_message(t('Updated @count keywords.', array('@count' => (int) $updated)));
}

function _kwgoals_pie_chart($title, $fields, $width = 300, $height = 150) {
 if (!module_exists('chart')) return;
  $chart = array(
    '#chart_id' => 'pie_chart_' . crc32($title),
    '#title' => $title,
    '#type' => CHART_TYPE_PIE_3D,
    '#size' => chart_size($width, $height),
    '#chart_fill' => chart_fill('a', ''),
    '#adjust_resolution' => TRUE,
    '#data' => $fields['#data'],
    '#legends' => $fields['#legends'] ? $fields['#legends'] : NULL,
  ); 
  return theme('chart', array('chart' => $chart)); 
}

function _kwgoals_chart_tracked_keywords($width = 300, $height = 150) {
  $untracked = db_query("SELECT count(*) FROM {kwgoals_kw} WHERE updated = 0")->fetchField();
  $tracked = db_query("SELECT count(*) FROM {kwgoals_kw} WHERE updated > 0")->fetchField(); 
  if (!($total = $tracked + $untracked)) return;
  $title = number_format($total) . " " . t('Total Keywords');
  $fields = array(
    '#data' => array(
      'tracked' => $tracked / $total,
      'untracked' => $untracked / $total,
    ),
    '#legends' => array(
      "Google data ($tracked)",
      "New ($untracked)",
    ),
  );
  return _kwgoals_pie_chart($title, $fields);
}

function _kwgoals_get_suggested_keywords($max = 20, $from = 0) {
  if (!$from) $from = strtotime("-30 day");   
 /* 
    $query = db_query("SELECT phrase, count(*) total FROM {kwgoals_hits} hits, {kwgoals_kw} kw
    WHERE hits.kwid = kw.kwid
     AND kw.serp>1 AND kw.updated>0 AND hits.date>%d 
    GROUP BY hits.kwid ORDER BY total DESC LIMIT %d", $from, $max)
 */
  $query = db_select('kwgoals_hits', 'hits')  
     ->fields('kw', array('phrase')) 
     ->condition('hits.date', $from, '>') 
     ->condition('kw.serp', 1, '>') 
     ->condition('kw.updated', 0, '>');
  $query->join('kwgoals_kw', 'kw', 'hits.kwid = kw.kwid');  
  $query->groupBy('hits.kwid'); 
  $query->orderBy('total', 'DESC'); 
  $query->range(0, $max);
  $query->addExpression('COUNT(*)', 'total');
  $result =  $query->execute();   
   
  $list = array();
  //while ($item = $result->fetchAssoc()) $list[] = $item['phrase']; 
  foreach ($result as $item) $list[] = $item->phrase;
  return $list;
}

/*
 * TODO: remove Goupby and make phrase unique key
 */
function _kwgoals_get_tracked_keywords($has_traffic = TRUE) { 
  if ($has_traffic) {
    $query = db_query("SELECT phrase, all_traffic FROM {kwgoals_kw} WHERE updated>0 AND all_traffic>0
      GROUP BY phrase ORDER BY all_traffic DESC");
  }
  else {
    $query = db_query("SELECT phrase, all_traffic FROM {kwgoals_kw} WHERE updated>0 AND all_traffic=0
     GROUP BY phrase");
  } 
  $list = array();
  while ($item = db_fetch_array($query)) $list[] = $item['phrase'];  
  return $list;
} 

function _kwgoals_suggested_content_table($max = 10) {
  // keywords with high weight for which we have no content
  $average_weight = db_query("SELECT avg(weight) FROM {kwgoals_kw} WHERE updated > 0 AND all_traffic>0")->fetchField();
  $query = db_query_range("SELECT phrase, (all_traffic*.43) max, weight FROM {kwgoals_kw} WHERE weight > :average_weight AND nid = 0
    ORDER BY WEIGHT DESC", 0, $max, array(':average_weight' => $average_weight));
  while ($row = db_fetch_array($query)) {
    $google_search = 'http://google.com/search?q=site:' . $_SERVER['HTTP_HOST'] . '+' . urlencode($row['phrase']);
    $row['best_match'] = l(t('find "@phrase" content', array('@phrase' => $row['phrase'])),   $google_search, array('attributes' => array('target' => '_blank')));
    $row['max'] = number_format($row['max']);
    $row['weight'] = number_format($row['weight']);
    $rows[] = $row;
  }
  if ($rows) {
    $header = array('Keyword Phrase', 'Est Max Traffic', 'Est Importance', 'Suggested Content');
    $table = array('header' => $header, 'rows' => $rows);
    return theme('table', $table);
  }
}

function _kwgoals_suggested_links_table($max = 10) {
  // keywords with high weight for which we have content
  $query = db_query_range("SELECT phrase, (all_traffic * .43) max, weight, nid, serp FROM {kwgoals_kw}
    WHERE nid > 0 AND serp > 1 AND weight > 0
    ORDER BY WEIGHT DESC", 0, $max);
  while ($row = db_fetch_array($query)) {
    $row['max'] = number_format($row['max']);
    $row['weight'] = number_format($row['weight']);
    $rows[] = $row;
  }
  if ($rows) {
    $header = array('Keyword Phrase', 'Est Max Traffic', 'Est Importance', 'Page', 'SERP'); 
    $table = array('header' => $header, 'rows' => $rows);
    return theme('table', $table); 
  }
}

function _kwgoals_first_page_cloud($min = 1, $max = 9, $has_traffic = TRUE) {
  // keywords with high weight for which we have no content
  if ($has_traffic) {
    $query = db_query("SELECT phrase, nid, serp, weight FROM {kwgoals_kw} WHERE serp >= :min AND serp <= :max AND all_traffic > 0 ORDER BY competition DESC", 
      array(':min' => $min, ':max' => $max));
    while ($r = db_fetch_array($query))  $rows[$r['phrase']] = $r;
    
    $query = db_query("SELECT kw.phrase, kw.nid, kw.serp, COUNT(hit.kwid) traffic FROM {kwgoals_kw} kw, {kwgoals_hits} hit WHERE kw.kwid = hit.kwid 
      AND hit.date > :from AND kw.weight = 0 AND kw.serp >= :min and kw.serp <= :max
       GROUP BY hit.kwid ORDER BY traffic DESC", 
       array(':from' => strtotime("-1 month"), ':min' => $min, ':max' => $max));
    while ($r = db_fetch_array($query)) if ($r['traffic'] > 1) $rows[$r['phrase']] = $r;  
  }
  else {
    $query = db_query("SELECT phrase, nid, serp, weight FROM {kwgoals_kw} 
      WHERE serp >= :min AND serp <= :max AND all_traffic = 0 ORDER BY competition DESC", 
      array(':min' => $min, ':max' => $max));
    while ($r = db_fetch_array($query)) $rows[] = $r; 
  } 
  if (!isset($rows)) return;
  foreach ($rows as $r) {
    $weight = $r['weight'];
    $phrase = $r['phrase'];
    $nid = $r['phrase'];
    $serp = $r['serp'];
    $link = l($phrase, 'node/' . $nid, array('attributes' => array('target' => '_blank', 'class' => 'pos_' . $serp)));
    $links[] = $link;
  }
  return "<div class='keyword_serp_cloud'>" . implode(', ', $links) . "</div>";
}

function _kwgoals_traffic_trend_chart($from, $width = 300, $height = 150) {
  if (!module_exists('chart')) return;
  $query = db_query("SELECT FROM_UNIXTIME(date, '%Y-%j') day, count(*) hit_count FROM {kwgoals_hits}
    WHERE date > :from GROUP BY day", array(':from' => $from));
  while ($row = db_fetch_array($query)) $hit_counts[] = $row['hit_count']; 
  if (!isset($hit_counts)) return;
  foreach ($hit_counts as $count) {
    $i++;
    $smooth = 7;
    $hit_counts_rounded[] = round(array_sum(array_slice($hit_counts, max($i -$smooth + 1, 0), $smooth)) / count(array_slice($hit_counts, max($i -$smooth + 1, 0), $smooth)), 4);
  }
  $chart = array(
    '#chart_id' => 'trend_traffic_line',
    //  '#title' => chart_title(t('Search Engine Traffic Trend'), 'cc0000', 15),
    '#type' => CHART_TYPE_LINE,
    '#size' => chart_size(800, 200),
    '#chart_fill' => chart_fill('c', 'FFFFFF'),
    '#grid_lines' => chart_grid_lines(100, 5, 1, 1),
    '#adjust_resolution' => TRUE,
  );
  $chart['#data'][] = $hit_counts;
  $chart['#data'][] = $hit_counts_rounded;
  $chart['#legends'][] = t('Raw');
  $chart['#legends'][] = t('Trend');
  $chart['#data_colors'][] = 'cccccc';
  $chart['#data_colors'][] = '0000dd';
 
  return theme('chart', array('chart' => $chart)); 
}

function _kwgoals_keyword_trend_chart($from, $width = 300, $height = 150) {
  if (!module_exists('chart')) return;
  // show a chart that shows growth in number of keyword hits from Google over time
  $query = db_query('SELECT FROM_UNIXTIME(date, \'%Y-%j\') day, COUNT(DISTINCT kwid) kw_count 
    FROM {kwgoals_hits} WHERE date > :from GROUP BY day', array(':from' => $from));
  while ($row = db_fetch_array($query)) $kw_count[] = $row['kw_count']; 
  if (!isset($kw_count)) return;
  foreach ($kw_count as $count) {
    $i++;
    $smooth = 7;
    $kw_count_rounded[] = round(array_sum(array_slice($kw_count, max($i -$smooth + 1, 0), $smooth)) / count(array_slice($kw_count, max($i -$smooth + 1, 0), $smooth)), 4);
  }
  $chart = array(
    '#chart_id' => 'trend_keyword_line',
    //  '#title' => chart_title(t('Search Engine Traffic Trend'), 'cc0000', 15),
    '#type' => CHART_TYPE_LINE,
    '#size' => chart_size(800, 200),
    '#chart_fill' => chart_fill('c', 'FFFFFF'),
    '#grid_lines' => chart_grid_lines(100, 5, 1, 1),
    '#adjust_resolution' => TRUE,
  );
  $chart['#data'][] = $kw_count;
  $chart['#data'][] = $kw_count_rounded;
  $chart['#legends'][] = t('Raw');
  $chart['#legends'][] = t('Trend');
  $chart['#data_colors'][] = 'cccccc';
  $chart['#data_colors'][] = '0000dd';

  return theme('chart', array('chart' => $chart)); 
}


function _kwgoals_ago($tm, $rcs = 0) {
  $cur_tm = REQUEST_TIME;
  $dif = $cur_tm -$tm;
  $pds = array('second', 'minute', 'hour', 'day', 'week', 'month', 'year', 'decade');
  $lngh = array(1, 60, 3600, 86400, 604800, 2630880, 31570560, 315705600);
  for ($v = sizeof($lngh) -1; ($v >= 0) && (($no = $dif / $lngh[$v]) <= 1); $v--) {
  }
  if ($v < 0) {
    $v = 0;
  }
  $_tm = $cur_tm -($dif % $lngh[$v]);
  $no = floor($no);
  if ($no <> 1) {
    $pds[$v] .= 's';
  }
  $x = sprintf("%d %s ", $no, $pds[$v]);
  if (($rcs == 1) && ($v >= 1) && (($cur_tm -$_tm) > 0)) {
    $x .= time_ago($_tm);
  }
  return $x;
}


/**
 * @todo Please document this function.
 * @see http://drupal.org/node/1354
 */
function kwgoals_suggestions_report() { 
kwgoals_update_blink_goals_cronjob();  // here for testing
  $output  = "<style>table.sticky-table {width:100%}
    table.sticky-table tr.odd {background:white;}
    table.sticky-table tr.odd td {padding:3px;}
   </style>";
  $output .= "<h3> Links Building: Best Growth Potential Keywords</h3>";
  $output .= _kwgoals_suggested_links_table(100);

  /*
   $count_above_average = db_result(db_query("SELECT COUNT(*) FROM kwgoals_kw WHERE nid>0 AND
   all_traffic > (SELECT AVG(all_traffic) FROM kwgoals_kw WHERE all_traffic>0 AND nid>0) "));
   $all_tracked_links = db_result(db_query("SELECT COUNT(*) FROM kwgoals_kw WHERE all_traffic>0 AND nid>0"));
   $output .= "<p> {$count_above_average} above-average traffic keywords tracked out of {$all_tracked_links}";
   */


  $output .= "<br/><br/><br/>";
  $output .= "<h3> New Content: Best Keywords without Content</h3>";

  $output .= _kwgoals_suggested_content_table(15);
  return $output;
}

/**
 * @todo Please document this function.
 * @see http://drupal.org/node/1354
 */
function kwgoals_summary_report() {
  $output = "<style>table.sticky-table {width:100%}
    table.sticky-table tr.odd {background:white;}
    table.sticky-table tr.odd td {padding:3px;}
    .keyword_serp_cloud {margin:10px; padding:10px; border: 3px solid silver; font-size:18px;}
    .keyword_serp_cloud .pos_1 {color:#F00000}
    .keyword_serp_cloud .pos_2 {color:#D00000}
    .keyword_serp_cloud .pos_3 {color:#B00000}
    .keyword_serp_cloud .pos_4 {color:#900000}
    .keyword_serp_cloud .pos_5 {color:#700000}
    .keyword_serp_cloud .pos_6 {color:#500000}
    .keyword_serp_cloud .pos_7 {color:#300000}
    .keyword_serp_cloud .pos_8 {color:#100000}
    .keyword_serp_cloud .pos_9 {color:#000000}
   </style>";
  // keyword traffic over time
  $from = strtotime("-6 month");
  $output .= "<br/>";
  $earliest = db_query_range("SELECT date FROM {kwgoals_hits} WHERE date>:from ORDER BY date ASC", 0, 1, array(':from' => $from))->fetchField();
  $ago = ucwords(_kwgoals_ago($earliest));
  $output .= "<h3> Keywords Traffic Trend Over the Last {$ago} </h3>";
  $output .= _kwgoals_traffic_trend_chart($from);
  $output .= "<br/><h3> Number of Keywords sending Traffic Last {$ago} </h3>";
  $output .= _kwgoals_keyword_trend_chart($from);

  // keywords in #1 and #2 position
  $output .= "<br/><br/>";
  if ($first_page_cloud = _kwgoals_first_page_cloud(1, 10)) {
    $output .= "<h3>" . t('Extremely Competitive Keywords with Page 1 Google SERP') . "</h3>" . $first_page_cloud; 
  }
  // keywords in #1 and #2 position
  if ($second_page_cloud = _kwgoals_first_page_cloud(10, 100)) {
    $output .= "<h4>" . t('Page 2+') . "</h4>" . $second_page_cloud;
  }

  // keywords in #1 and #2 position
  if ($other_top_keywords = _kwgoals_first_page_cloud(1, 10, FALSE)) {
    $output .= "<br/><br/>";
    $output .= "<h3>" . t('Longtail Keywords with Page 1 Google SERP') . "</h3>" . $other_top_keywords;
  }

  return $output;
}

 
