<?php

/*
 *  $file 
 *
 *  Lightweight stub, task is to validate and record hits sent from 
*/
  //error_reporting(0); // hide errors from returning to caller
  $_MAX_INTERVAL = strtotime('-3 minute'); // reject duplicate requests within 3 minutes
  
  if (!_kwgoals_validate()) exit;  
  if (!_kwgoals_drupal_bootstrap_db()) exit;
  include_once('kwgoals.admin.inc');
    
  $nid = _kwgoals_nid(); 
  $phrase = _kwgoals_phrase();
  $serp = _kwgoals_serp(); 
  $ip = _kwgoals_ip();
 
  // check if this is a duplicate request, if so discard
  $kwgoals_kw = db_fetch_array(db_query("SELECT * FROM {kwgoals_kw} WHERE phrase=:phrase",
   array(':phrase' => $phrase)));    
  if ($kwgoals_kw['kwid'] && db_result(db_query("SELECT count(*) FROM {kwgoals_hits} WHERE nid=:nid  AND kwid=:kwid AND ip=:ip AND date>:date", array(
    ':nid' => $nid, 
    ':kwid' => $kwgoals_kw['kwid'], 
    ':ip' => $ip, 
    ':date' => $_MAX_INTERVAL,    
  )))) {
    //echo "oops, this hit matches a previous one, discarding.";
    exit;
  } 
 
  // insert or update keyword  
  if ($kwgoals_kw['kwid']) {  // update
    if ($serp < $kwgoals_kw['serp']) { // if serp had improved, update serp, nid and potential
      $kwgoals_kw['serp'] = $serp;
      $kwgoals_kw['nid'] = $nid; 
    } else if ($kwgoals_kw['nid'] == $nid) { // if nid the same, update serp anyway
      $kwgoals_kw['serp'] = $serp;
    }
    $kwgoals_kw['potential'] = _kwgoals_estimate_potential_growth($kwgoals_kw['serp'], $kwgoals_kw['all_traffic']); 
    $kwgoals_kw['weight'] = _kwgoals_estimate_link_weight($kwgoals_kw['potential'], $kwgoals_kw['competition']);
  } else {
    $kwgoals_kw = array(
      'phrase'       => $phrase, 
      'nid'          => $nid,
      'serp'         => $serp,
    );   
  } 
  drupal_write_record('kwgoals_kw', $kwgoals_kw, isset($kwgoals_kw['kwid']) ? array('kwid') : array()); 
   
  // insert new hit
  $kwgoals_hits = array( 
    'nid'          => $nid,
    'kwid'         => $kwgoals_kw['kwid'],
    'ip'           => $ip,
    'date'         => time(),
    'serp'         => $serp, 
  );
  drupal_write_record('kwgoals_hits', $kwgoals_hits);  
  // echo "Inserted new keyword: {$kwgoals_kw['kwid']}";  
  exit;   

  

/*
 * Tools  ============================================
*/


function _kwgoals_drupal_bootstrap_db() {
  if (!$depth = count(explode('/', substr(getcwd(), strpos(getcwd(), '/sites/', 0)+1)))) return FALSE;  
  chdir(str_repeat('../', $depth)); 
  define('DRUPAL_ROOT', getcwd());
  require_once DRUPAL_ROOT . '/includes/bootstrap.inc'; 
  require_once DRUPAL_ROOT . '/includes/common.inc';
  drupal_bootstrap(DRUPAL_BOOTSTRAP_DATABASE);   
  return TRUE;
} 

function _kwgoals_validate() {
  if (_kwgoals_phrase() && _kwgoals_serp() && _kwgoals_nid() && (int)_kwgoals_ip()) return TRUE;  
}

/*
 *  remove stopwords, punctuation etc
 */
function _kwgoals_phrase() {
  $keyword_phrase = urldecode($_GET['q']);
  $MAX_WORDCOUNT = 5; 
  $stopwords = explode('|', "the|of|and|a|to|in|is|you|that|it|he|was|for|on|are|as|with|his|they|I|at|be|this|have|from|or|one|had|by|word|but|not|what|all|were|we|when|your|can|said|there|use|an|each|which|she|do|how|their|if|will|up|other|about|out|many|then|them|these|so|some|her|would");
  $nopunctuation = strtolower(preg_replace('/\W/', ' ', $keyword_phrase));
  $words = explode(' ', $nopunctuation);
  foreach ($words as $word) if (!in_array($word, $stopwords)) $new[] = $word;
  if (count($new) > 0 && count($new) <= $MAX_WORDCOUNT) return implode(' ', $new);
}

function _kwgoals_serp() {
  $serp = (int) urldecode($_GET['s']);
  if (($serp>0) && ($serp<101)) return $serp;
}

function _kwgoals_nid() {
  $nid = (int) urldecode($_GET['id']);
  if (($nid>0) && ($nid<100000000)) return $nid; // sanity check
}

function _kwgoals_ip() {
  $ip = trim($_SERVER['REMOTE_ADDR']);
  $ip = sprintf("%u", ip2long($ip));
  return $ip;
}
