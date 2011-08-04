
function kwgoals() { 
  if (document.referrer === '') return; 
  if (document.referrer.search('google.com') < 0) return;  
  params = document.referrer.split("&");
  for (i=0; i<params.length; i++) {
    pair = params[i].split("=");
    if (pair[0] == 'q') {
      MAX_PHRASE_LENGTH = 5;
      COMMON_WORDS = "the|of|and|a|to|in|is|you|that|it|he|was|for|on|are|as|with|his|they|I|at|be|this|have|from|or|one|had|by|word|but|not|what|all|were|we|when|your|can|said|there|use|an|each|which|she|do|how|their|if|will|up|other|about|out|many|then|them|these|so|some|her|would";
      stopwords = new RegExp("\\b("+COMMON_WORDS+")\\b", "ig");
      query = unescape(pair[1]).toLowerCase() 
              .replace(stopwords, "")  // remove common words
              .replace(/[^A-Za-z0-9]+/gi, " ")  // remove non-alphanumerics
              .replace("  ", " ") // replace double space with single
              .replace(/^\s+|\s+$/g,"");  // trim blank spaces from sides
      if (query.length < 4) return; // no keywords less than four characters are probable   
      if (query.split(" ").length > MAX_PHRASE_LENGTH) return; // more than 5 words is too far down the long tail 
    }
     else if (pair[0] == 'cd') {
       serp = pair[1].replace(/^\s+|\s+$/g,""); // trim
       if (isNaN(parseInt(serp))) return; // make sure it's a number
       if (serp > 100) return; // should not be > 100
     }
  }
  if (typeof query === 'undefined' || typeof serp === 'undefined') return; // final check
  // ready to send 
  url = kwgoals_tracker + '?id='+escape(kwgoals_nid)+'&q='+escape(query)+'&s='+escape(serp); 
  
  //document.title = url; exit;
  
  $.get(url, function(data) {
     // document.title=data; // for debugging
  }); 
} 

$(document).ready(kwgoals); 


 

 
 
 
 
