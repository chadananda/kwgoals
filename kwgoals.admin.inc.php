<?php


define('KWGOALS_DAMPEN_TOP', 50); // % to decrease potential of serp1 links - the more we do, the more focus other links get
define('KWGOALS_COMPETITION_WEIGHT', 50); // % how much of our weight should be affected by Google's compeition ratio



function kwgoals_update_blink_goals_cronjob() { 
  // get list of goals from blink
  $goals = blink_get_keyword_goals('kwgoals'); // returns full record array
  //drupal_set_message("Blink has ". count($goals) ." kwgoals goals"); 
  // build index by keyword, since our keywords are unique
  if ($goals) foreach ($goals as $row) $indexed[$row['kw']] = $row; 
  // build desired list of keywords with recognized potential
  $query = db_query("SELECT phrase, nid, weight FROM {kwgoals_kw} WHERE weight>0 AND nid>0");
  while ($kw = db_fetch_array($query)) $kwgoals[$kw['phrase']] = $kw;   
  // Add in keywords where serp>0, traffic>0 but all_traffic=0  
  $query = db_query("SELECT kw.phrase, kw.nid, kw.serp, COUNT(hit.kwid) traffic 
    FROM kwgoals_kw kw, kwgoals_hits hit WHERE kw.kwid=hit.kwid  
      AND hit.date>%d AND kw.weight=0 AND kw.serp>1
     GROUP BY hit.kwid ORDER BY traffic DESC", strtotime("-1 month"));
  while ($kw = db_fetch_array($query)) if(!$kwgoals[$kw['phrase']] ) {
   $kw['weight'] = _kwgoals_estimate_weight_from_traffic($kw['serp'], $kw['traffic']);
   if ($kw['weight'] > 5) { // remove riff-raff
     if ($kw['weight'] > 50) $kw['weight'] = 50;
     $kwgoals[$kw['phrase']] = $kw;  
   }
  }
  //drupal_set_message("Kwgoals found ". count($kwgoals) ." keywords that should be blink goals");   
  // loop through and update or add, then remove from list 
  if ($kwgoals) foreach ($kwgoals as $kw) {
    $url = url('node/'.$kw['nid'], array('absolute'=>TRUE)); 
    $phrase = $kw['phrase']; 
    $weight = $kw['weight'];
    if ($goal = $indexed[$phrase]) { // update or ignore 
      if (($goal['url']!=$url) || ($goal['weight']!=$weight)) {
        $goal['url'] = $url; 
        $goal['weight'] = $weight;
        blink_update_keyword_goal($goal, 'kwgoals'); 
        //drupal_set_message("Updated blink keyword '{$phrase}'"); 
      } // else drupal_set_message("Keyword already a blink goal: '{$phrase}'");    
      unset($indexed[$phrase]);
    } else { // add new
      blink_add_keyword_goal($phrase, $url, $weight, 'kwgoals');  
      //drupal_set_message("Added blink keyword '{$phrase}'");
      unset($indexed[$phrase]);   
    } 
  } 
  // delete any remaining items
  if ($indexed) foreach ($indexed as $goal) { 
    //drupal_set_message("Deleted blink keyword '{$goal['kw']}'");
    blink_delete_keyword_goal($goal, 'kwgoals');  
  } 
}


// provide a tag cloud for node blocks, randomized and sized to potential
// same block should be universal when not on node page
function kwgoals_block($op = 'list', $delta = 0) {
  $block = array();
  switch ($op) {
    case 'list':
      $block[0]['info'] = t('Page Keywords');
      $block[1]['info'] = t('Site Keywords');
      return $block;
    case 'view':
      switch ($delta) {
        case 0:
          $block['subject'] = t('Page Keywords');
          $block['content'] = _kwgoals_keyword_cloud(TRUE, 100, 'page_search');
          break;
        case 1:
          $block['subject'] = t('Site Keywords');
          $block['content'] = _kwgoals_keyword_cloud(FALSE, 100, 'site_search');
          break;
      }
      return $block;
  }
}

function _kwgoals_keyword_cloud($page_only=TRUE, $max=100, $class='kwgoals') {
  if ($page_only && (int) arg(1)) $this_nid = (int) arg(1);   
  // keywords with high weight for which we have content 
  if ($this_nid) $query = db_query("SELECT phrase, weight, nid, serp, competition FROM {kwgoals_kw} WHERE nid=%d ORDER BY WEIGHT DESC LIMIT %d", $this_nid, $max);
    else $query = db_query("SELECT phrase, weight, nid, serp FROM {kwgoals_kw} WHERE nid>0 AND serp>1 ORDER BY WEIGHT DESC LIMIT %d", $max);  
  // build link list with weight based on serp
  while ($r = db_fetch_array($query)) $links[] = l($r['phrase'], 'node/'.$r['nid'], array('attributes' => array('class' => 'pos_'.$r['serp'])));
  if ($links) return "<div class='{$class}'>". implode(', ', $links) ."</div>"; 
} 

 // settings form
 //================

function kwgoals_add_keywords_form() {   
  $form['kwgoals_keywords']  = array(
    '#type'         => 'fieldset',
    '#title'        => t('Keyword Goal Phrases'),
    '#description'  => '<h3>Paste these keywords into Google\'s  '.
      l('External Keywords Tool', 'http://bit.ly/9FqW8F', array('attributes'=>array('target'=>'_blank'))).
        ' then upload resulting CSV file. </h3>',
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );  
  $tracked_with_traffic = _kwgoals_get_tracked_keywords(TRUE);
  $tracked_no_traffic =  _kwgoals_get_tracked_keywords(FALSE); 
  $tracked_all = array_merge($tracked_with_traffic, $tracked_no_traffic); 
  
  $title = number_format(count($tracked_all)) ." ". t("Google Keywords");   
  $fields = array(
    '#data' => array(
      'competitive' => count($tracked_with_traffic) / count($tracked_all),
      'obscure' => count($tracked_no_traffic) / count($tracked_all),
    ),
    '#legends' => array(
      "Competitive (". count($tracked_with_traffic) .")",
      "Obscure (". count($tracked_no_traffic) .")",
    ),  
  );  
  $chart_rendered = _kwgoals_pie_chart($title, $fields);
 
 
 
  $form['kwgoals_keywords']['koc_keywords_cluster'] = array(
    '#type'     => 'textarea',
    '#title'    => t('Website keyword cluster') .", ". count($tracked_all) ." ". t("keywords") ." ". count($tracked_with_traffic) ." ". t("competitive"),
    '#attributes' => array('readonly' => "readonly"),
    '#default_value' => implode("\n", $tracked_all),
    '#description'  => t('Main keyword targets for this website (read only).'),
    '#prefix' => "<div style='float:right; margin-top:0px;'>{$chart_rendered}</div><div style='margin-right:310px;'>",
    '#suffix' => "</div>",
  );   
  $form['kwgoals_keywords']['kwgoals_csv_upload'] = array(
    '#type' => 'file', 
    '#title' => t('Upload CSV File'),
    '#description'  => t('After generating a CSV file with the Google External Keywords tool, upload it here.'),
    '#size' => 20,
  );   
  $form['kwgoals_keywords']['kwgoals_submit'] = array(
    '#type'     => 'submit',
    '#value'    => t('Upload CSV'),
    '#suffix'   => '<hr/>',
  );  
   
  $chart_rendered = _kwgoals_chart_tracked_keywords();   
  $form['kwgoals_keywords']['kwgoals_keywords_suggested'] = array(
    '#type'     => 'textarea',
    '#title'    => t('Top Google hit keywords to consider adding'),
    '#attributes' => array('readonly' => "readonly"),
    '#default_value' => implode("\n", _kwgoals_get_suggested_keywords(100)),
    '#description'  => t('To add these, paste them into the External Keyword Tool and upload the resulting CSV.'), 
    '#prefix' => "<div style='float:right; margin-top:0px;'>{$chart_rendered}</div><div style='margin-right:310px;'>",
    '#suffix' => "</div>",
  );   

  $form['#attributes']['enctype'] = 'multipart/form-data';
  $form['kwgoals_keywords']['kwgoals_example_import_image'] = array(
    '#value' => '<br/><img src="http://bit.ly/hWAbqW" style="width:95%; margin:5px; padding:5px; border:1px solid silver;" /><br/>',
    '#weight' => 10,
  );  

  return $form; 
}
function kwgoals_add_keywords_form_validate($form, &$form_state){
  $file = file_save_upload('kwgoals_csv_upload');
  if (!file_exists($file->filepath)) form_set_error('kwgoals_csv_upload', t("CSV file is required"));  
  
  // checking the data first for all fields then for keyword type "exact"
  include_once('csv_reader.class.php'); 
  // Keyword,Competition,Global Monthly Searches,Local Monthly Searches
  $slice = array('Keyword','Competition','Global Monthly Searches');
  if (!($data = csv_reader::file_to_array($file->filepath, $slice))) {
    drupal_set_message('No data returned from this CSV file. Are you sure it\'s the right format (Just plain CSV)?', 'warning');
    return;
  }

  $row1 = array_shift($data);
  $csv_keys = array_keys($row1);
  foreach ($slice as $key) if (!in_array($key, $csv_keys)) {
    form_set_error('kwgoals_csv_upload', t("CSV file is missing fields."));
    return;
  }
  $ok = ((substr($row1['Keyword'], 0, 1) == '[') && (substr($row1['Keyword'], 0, 1)));
  if (!$ok) {
    form_set_error('kwgoals_csv_upload', t("CSV Keywords must be 'exact' match. Please re-download CSV file."));
    return;
  }
   else $form_state['values']['kwgoals_csv_upload'] = $file->filepath;
}
function kwgoals_add_keywords_form_submit($form, &$form_state) { 
  $csv_file = trim($form_state['values']['kwgoals_csv_upload']);
  if (!file_exists($csv_file)) {
    drupal_set_message("File not found: {$csv_file}", 'warning');
    return;
  }
  include_once('csv_reader.class.php'); 
  $slice = array('Keyword','Competition','Global Monthly Searches');
  $data = csv_reader::file_to_array($csv_file, $slice); 
  // loop through and add or update each keyword
  if (is_array($data)) foreach ($data as $kw) {
    // add or update record for each keyword  
    $keyword = trim(str_replace(array(",", ';', "'", '"', '[', ']'), "",  strtolower($kw['Keyword'])));
    if (!$keyword || substr($keyword,1,2)=='==') continue;  
    $kwgoals_kw = db_fetch_array(db_query("SELECT kwid,nid,serp FROM {kwgoals_kw} WHERE phrase='%s'", $keyword)); 
    $kwgoals_kw['phrase'] = $keyword;
    $kwgoals_kw['competition'] = round((int) ($kw['Competition'] * 100));
    $kwgoals_kw['all_traffic'] = (int) $kw['Global Monthly Searches']; 
    $kwgoals_kw['potential'] = _kwgoals_estimate_potential_growth($kwgoals_kw['serp'], $kwgoals_kw['all_traffic']); 
    $kwgoals_kw['weight'] = _kwgoals_estimate_link_weight($kwgoals_kw['potential'], $kwgoals_kw['competition']); 
    $kwgoals_kw['updated'] = (int) strtotime('now');
    drupal_write_record('kwgoals_kw', $kwgoals_kw, $kwgoals_kw['kwid'] ? 'kwid' : NULL);   
    $updated++;
  } 
  drupal_set_message('Updated '. (int)$updated .' keywords.');  
}

function _kwgoals_pie_chart($title, $fields, $width=300, $height=150) {
  $chart_rendered = chart_render(array(
    '#chart_id' => 'pie_chart_'.crc32($title),
    '#title' => $title,
    '#type' => CHART_TYPE_PIE_3D,
    '#size' => chart_size($width, $height),
    '#chart_fill' => chart_fill('a', ''),
    '#adjust_resolution' => TRUE,
    '#data' => $fields['#data'], 
    '#legends' => $fields['#legends'] ? $fields['#legends'] : NULL,
  ));
  return $chart_rendered;
}

function _kwgoals_chart_tracked_keywords($width=300, $height=150) {
  $untracked = db_result(db_query("SELECT count(*) FROM {kwgoals_kw} WHERE updated=0"));
  $tracked = db_result(db_query("SELECT count(*) FROM {kwgoals_kw} WHERE updated>0"));
  $title = number_format($tracked+$untracked) ." ". t('Total Keywords');
  $fields = array(
    '#data' => array(
      'tracked' => $tracked / ($untracked+$tracked),
      'untracked' => $untracked / ($untracked+$tracked),
    ),
    '#legends' => array(
      "Google data ($tracked)",
      "New ($untracked)",
    ),  
  );
  return _kwgoals_pie_chart($title, $fields); 
}
 
function _kwgoals_get_suggested_keywords($max=20, $from=0) {
  if (!$from) $from = strtotime("-30 day");
  $rows = db_query("SELECT phrase, count(*) total FROM kwgoals_hits hits, kwgoals_kw kw
    WHERE hits.kwid = kw.kwid
     AND hits.date>%d AND kw.serp>1 AND kw.updated=0
    GROUP BY hits.kwid ORDER BY total DESC LIMIT %d", $from, $max);
  $list = array();
  while ($item =  db_fetch_array($rows)) $list[] = $item['phrase']; 
  return $list;
}

/*
 * TODO: remove Goupby and make phrase unique key
*/
function _kwgoals_get_tracked_keywords($has_traffic=TRUE) {
  if ($has_traffic) $rows = db_query("SELECT phrase, all_traffic FROM kwgoals_kw WHERE updated>0 AND all_traffic>0
    GROUP BY phrase ORDER BY all_traffic DESC");
   else $rows = db_query("SELECT phrase, all_traffic FROM kwgoals_kw WHERE updated>0 AND all_traffic=0
    GROUP BY phrase");
  $list = array();
  while ($item =  db_fetch_array($rows)) $list[] = $item['phrase']; 
  return $list;
}

 




function _kwgoals_suggested_content_table($max=10) {
  // keywords with high weight for which we have no content
  $average_weight = db_result(db_query("SELECT avg(weight) FROM {kwgoals_kw} WHERE updated>0 AND serp>1")); 
  $top_without_content = db_query("SELECT phrase,(all_traffic*.43) max, weight FROM {kwgoals_kw} WHERE weight>%d AND nid=0
    ORDER BY WEIGHT DESC LIMIT %d", $average_weight, $max);
  while ($row = db_fetch_array($top_without_content)) {
    $google_search = 'http://google.com/search?q=site:'.$_SERVER['HTTP_HOST'].'+'. urlencode($row['phrase']);
    $row['best_match'] = l('find "'.$row['phrase'].'" content', $google_search, array('attributes' => array('target' => '_blank')));
    $row['max'] = number_format($row['max']);
    $row['weight'] = number_format($row['weight']);
    $rows[] = $row;
  }
  return theme_table(array('Keyword Phrase', 'Est Max Traffic', 'Est Importance', 'Suggested Content'), $rows); 
}

function _kwgoals_suggested_links_table($max=10) {
  // keywords with high weight for which we have content 
  $top_with_content = db_query("SELECT phrase, (all_traffic * .43) max, weight, nid, serp FROM {kwgoals_kw} 
    WHERE nid>0 AND serp>1 AND WEIGHT>0
    ORDER BY WEIGHT DESC LIMIT %d", $max);
  while ($row = db_fetch_array($top_with_content)) {
    $row['max'] = number_format($row['max']);
    $row['weight'] = number_format($row['weight']);
    $rows[] = $row; 
  }
  return theme_table(array('Keyword Phrase', 'Est Max Traffic', 'Est Importance', 'Page', 'SERP'), $rows); 
} 

function _kwgoals_first_page_cloud($min=1, $max=9, $has_traffic=TRUE) {
  // keywords with high weight for which we have no content 
  if ($has_traffic) {
    $query = db_query("SELECT phrase, nid, serp, weight FROM {kwgoals_kw} WHERE serp>=%d AND serp<=%d AND all_traffic>0 ORDER BY competition DESC", $min, $max); 
    while ($r = db_fetch_array($query)) $rows[$r['phrase']]=$r; 
    $query = db_query("SELECT kw.phrase, kw.nid, kw.serp, COUNT(hit.kwid) traffic FROM kwgoals_kw kw, kwgoals_hits hit 
      WHERE kw.kwid=hit.kwid AND hit.date>%d AND kw.weight=0 AND kw.serp>=%d and kw.serp<=%d
       GROUP BY hit.kwid ORDER BY traffic DESC", strtotime("-1 month"), $min, $max);
    while ($r = db_fetch_array($query)) if ($r['traffic']>1) $rows[$r['phrase']]=$r;      
  }  else {
    $query = db_query("SELECT phrase, nid, serp, weight FROM {kwgoals_kw} WHERE serp>=%d AND serp<=%d AND all_traffic=0 ORDER BY competition DESC", $min, $max);
    while ($r = db_fetch_array($query)) $rows[]=$r; 
  }  
  // while ($r = db_fetch_array($top_serps)) { 
  foreach ($rows as $r) {
    $weight = $r['weight']; 
    $phrase = $r['phrase'];
    $nid = $r['phrase'];
    $serp = $r['serp'];
    $link = l($phrase, 'node/'.$nid, array('attributes' => array('target'=>'_blank', 'class' => 'pos_'.$serp)));
    $links[] = $link;   
  } 
  return "<div class='keyword_serp_cloud'>". implode(', ', $links) ."</div>";
}

function _kwgoals_traffic_trend_chart($from, $width=300, $height=150) {
  $query = db_query("SELECT FROM_UNIXTIME(date, '%Y-%j') day, count(*) hit_count FROM kwgoals_hits 
    WHERE date>%d GROUP BY day", $from); 
  while ($row = db_fetch_array($query)) $hit_counts[] = $row['hit_count']; 
  foreach ($hit_counts as $count) { 
    $i++; $smooth = 7;
    $hit_counts_rounded[] = round(array_sum(array_slice($hit_counts, max($i-$smooth+1,0), $smooth))/count(array_slice($hit_counts, max($i-$smooth+1, 0), $smooth)),4);
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
   
  $chart_rendered = chart_render($chart); 
  return $chart_rendered; 
}

function _kwgoals_keyword_trend_chart($from, $width=300, $height=150) {
  // show a chart that shows growth in number of keyword hits from Google over time
  $query = db_query("SELECT FROM_UNIXTIME(date, '%Y-%j') day, COUNT(DISTINCT kwid) kw_count FROM kwgoals_hits 
    WHERE date>%d GROUP BY day", $from); 
  while ($row = db_fetch_array($query)) $kw_count[] = $row['kw_count']; 
  foreach ($kw_count as $count) { 
    $i++; $smooth = 7;
    $kw_count_rounded[] = round(array_sum(array_slice($kw_count, max($i-$smooth+1,0), $smooth))/count(array_slice($kw_count, max($i-$smooth+1, 0), $smooth)),4);
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
   
  $chart_rendered = chart_render($chart); 
  return $chart_rendered; 
}

 
function _kwgoals_ago($tm,$rcs = 0) {
    $cur_tm = time(); $dif = $cur_tm-$tm;
    $pds = array('second','minute','hour','day','week','month','year','decade');
    $lngh = array(1,60,3600,86400,604800,2630880,31570560,315705600);
    for($v = sizeof($lngh)-1; ($v >= 0)&&(($no = $dif/$lngh[$v])<=1); $v--); if($v < 0) $v = 0; $_tm = $cur_tm-($dif%$lngh[$v]);   
    $no = floor($no); if($no <> 1) $pds[$v] .='s'; $x=sprintf("%d %s ",$no,$pds[$v]);
    if(($rcs == 1)&&($v >= 1)&&(($cur_tm-$_tm) > 0)) $x .= time_ago($_tm);
    return $x;
}

 
function kwgoals_suggestions_report() { 

kwgoals_update_blink_goals_cronjob();


   $output .= "<style>table.sticky-table {width:100%} 
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

function kwgoals_summary_report() { 
   $output .= "<style>table.sticky-table {width:100%} 
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
     $earliest = db_result(db_query("SELECT date FROM kwgoals_hits WHERE date>%d ORDER BY date ASC LIMIT 1", $from));
     $ago = ucwords(_kwgoals_ago($earliest));
   $output .= "<h3> Keywords Traffic Trend Over the Last {$ago} </h3>";
   $output .= _kwgoals_traffic_trend_chart($from);  
   $output .= "<br/><h3> Number of Keywords sending Traffic Last {$ago} </h3>";
   $output .= _kwgoals_keyword_trend_chart($from);    
   
   // keywords in #1 and #2 position
   $output .= "<br/><br/>";
   $output .= "<h3> Extremely Competitive Keywords with Page 1 Google Hits</h3>";   
   $output .= _kwgoals_first_page_cloud(1,10);  
   // keywords in #1 and #2 position 
   $output .= "<h4> Page 2+ </h4>";
   $output .= _kwgoals_first_page_cloud(10,100);   
   
   // keywords in #1 and #2 position
   $output .= "<br/><br/>";
   $output .= "<h3> Other Related Keywords with Page 1 Google Hits</h3>";
   $output .= _kwgoals_first_page_cloud(1,10,FALSE);   
   
  return $output;
}


















// ==========================================================
//   shared algorithms to calculate weights
// ==========================================================


 // just give a higher weight the lower the serp - for when we have no google data
 function _kwgoals_estimate_weight_from_traffic($serp, $current_traffic) {
  return $current_traffic * $serp * (round($serp/10)); 
 }

 function _kwgoals_estimate_link_weight($potential, $competition, $weighted = KWGOALS_COMPETITION_WEIGHT) {
   $weighted_portion = $potential * $weighted / 100;
   $unweighted_portion = $potential - $weighted_portion; 
   $weighted_portion = $competition ? $weighted_portion - ($weighted_portion / (100/$competition)) : 0;
   $result = $unweighted_portion + $weighted_portion; // $weight/1 - $weight/100
   return $result;
 }

 // estimated traffic increase from one step up the serp ladder
 function _kwgoals_estimate_potential_growth($serp, $all_traffic) {
   if (!$all_traffic) return 0; 
   $current_traffic_est = _kwgoals_estimate_serp_traffic($serp, $all_traffic);
   if ($serp == 1) return round($current_traffic_est / (100/KWGOALS_DAMPEN_TOP)); // dampen top keyword
   $max_possible_traffic = _kwgoals_estimate_serp_traffic(1, $all_traffic);
   return $max_possible_traffic - $current_traffic_est; 
 } 

 // estimate traffic for a serp
 function _kwgoals_estimate_serp_traffic($serp, $all_traffic) {
  if (!$all_traffic || !$serp) return 0; 
  // formulae should basically match the serp click distributions
  $dist=array(42.3,11.92,8.44,6.03,4.86,3.99,3.37,2.98,2.83,2.97,0.66,0.66,0.52,0.48,0.47,0.39,0.36,0.34,0.32,0.30,0.29,
   0.27,0.24,0.22,0.20,0.18,0.16,0.14,0.12,0.12,0.12,0.12,0.12,0.11,0.11,0.10,0.10,0.09,0.09,0.08,0.08,0.07);
  if ($serp < count($dist)) return round($dist[$serp-1] * $all_traffic / 100);  
  $used = array_sum($dist);
  for ($i = 41; $i<100; $i++) {
    $remaining_allotment = (float) 100.0000 - $used;
    $remaining_steps = 100 - $i;
    $multiplier = round($remaining_allotment / $remaining_steps  * 1.5, 4);
    $used += $multiplier;
    if (($i>=$serp) || ($used > 100)) return round($multiplier * $all_traffic / 100);  
  }
 }






