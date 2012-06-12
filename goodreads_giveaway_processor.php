<?php
define('GOODREADS_BASE_URL', 'http://www.goodreads.com');
include('./simple_html_dom.php');
include('./gr_creds.php');

$page = isset($_GET['page']) ? (int)$_GET['page'] : (!empty($argv[1]) ? (int)$argv[1] : NULL);
$gr_tab = isset($_GET['tab']) ? (int)$_GET['tab'] : (!empty($argv[2]) ? $argv[2] : 'recently_listed');
if (!empty($gr_debug)) {
  $folder = './debug-' . date('Y-m-d_H_i_s');
  mkdir($folder);
  if (is_dir($folder)) {
    $gr_debug = $folder . '/';
    echo "Debug files in: $gr_debug\n";
  }
  else {
    $gr_debug = '';
  }
}

if (goodreads_login($gr_un, $gr_pw)) {
  goodreads_process_giveaways($page);
}
_goodreads_curl('http://www.goodreads.com/', array(), TRUE);
unlink(getcwd() . '/gr.tmp');


function goodreads_login($u, $p) {
  global $gr_debug;
  static $run = FALSE;
  if ($run) {
    return;
  }
  $run = TRUE;

  $headers = $values = array();
  $url = 'https://www.goodreads.com/user/sign_in';
  $output = _goodreads_curl($url);

  // User is already logged in.
  if (_goodreads_is_logged_in($output)) {
    return TRUE;
  }

  // Search for login form.
  $html = str_get_html($output);
  $form = $html->find('form[name=sign_in]');
  $form = array_pop($form);
  if (!$form) {
    die('Could not find the sign_in form.');
  }
  // Add in the hidden values.
  foreach ($form->find('input[type=hidden]') as $input) {
    $values[$input->name] = $input->value;
  }
  $values['user[email]'] = $u;
  $values['user[password]'] = $p;
  $headers[CURLOPT_POST] = TRUE;
  $headers[CURLOPT_POSTFIELDS] = http_build_query($values);
  $output = _goodreads_curl($url, $headers);
  if ($gr_debug) {
    file_put_contents($gr_debug . 'login.html', $output);
  }
  if (!_goodreads_is_logged_in($output)) {
    die ('Failed to login (could not find profile link)');
  }
  echo "Logged In\n";
  //echo $output;
  return TRUE;
}

/**
 * Check if logged in.
 */
function _goodreads_is_logged_in($output) {
  $html = str_get_html($output);
  $logged = $html->find('body div[class="content"] div[id="siteheader"] div[class="mainContent"] ul[id="usernav"] a[class="profileSubNavLink"]');
  if (!$output || empty($logged)) {
    return FALSE;

  }
  return TRUE;
}

function goodreads_process_giveaways($page = NULL) {
  global $gr_debug, $gr_tab;
  if (!$page) $page = 1;
  $baseurl = "http://www.goodreads.com/giveaway?sort={$gr_tab}&tab={$gr_tab}";
  $total_processed = $total_found = 0;
  do {
    $processed = $found = 0;
    echo "Page {$page}:";
    $output = _goodreads_curl($baseurl . '&page=' . $page);
    if ($gr_debug) {
      file_put_contents($gr_debug . 'giveawaypage-' . $page . '.html', $output);
    }
    $html = str_get_html($output);
    $giveaways = $html->find('div[class=actions] a[href^=/giveaway/enter_choose_address]');
    $total = count($giveaways);
    echo $total . " giveaways\n";
    foreach ($giveaways as $giveaway) {
      $found++;
      if (_goodreads_select_address_and_process_giveaway(GOODREADS_BASE_URL . $giveaway->href)) {
        $processed++;
        echo "Processed $page-$found/$total: {$giveaway->href}\n";
      }
      else {
        echo "FAILED $page-$found/$total: {$giveaway->href}\n";
      }
    }

    $total_processed += $processed;
    $total_found += $found;
    $page++;
  } while ($processed);

  echo "$total_processed/$total_found";
  return $total_processed;
}

function _goodreads_select_address_and_process_giveaway($url) {
  global $gr_debug;
  $output = _goodreads_curl($url);
  if ($gr_debug) {
    file_put_contents($gr_debug . 'choose_address_' . basename($url) . '.html', $output);
  }
  $html = str_get_html($output);
  $addresses = $html->find('div[id=addresses] a[href^=/giveaway/enter_choose_address]');

  if (empty($addresses)) {
    return FALSE;
  }
  $address = reset($addresses);

  $headers = array();
  $headers[CURLOPT_POST] = TRUE;
  $output = _goodreads_curl(GOODREADS_BASE_URL . $address->href, $headers);
  if ($gr_debug) {
    file_put_contents($gr_debug . 'entry_form_' . basename($url) . '.html', $output);
  }

  $html = str_get_html($output);
  $form = $html->find('form[name=entry_form]');
  $form = array_pop($form);
  if (!$form) {
    return FALSE;
    die('Could not find the entry_form form.');
  }
  // Add in the hidden values.
  foreach ($form->find('input[type=hidden]') as $input) {
    $values[$input->name] = $input->value;
  }
  $values['commit'] = 1;
  $headers = array();
  $headers[CURLOPT_POST] = TRUE;
  $headers[CURLOPT_POSTFIELDS] = http_build_query($values);

  // Process actually submitting the final form.
  $output = _goodreads_curl(GOODREADS_BASE_URL . $form->action, $headers);
  if ($gr_debug) {
    file_put_contents($gr_debug . 'completed_' . basename($url) . '.html', $output);
  }

  $html = str_get_html($output);
  $processed = $html->find('div[id="header_notice_container"] div[class="noticeBox"]');
  return !empty($processed);
}

function _goodreads_curl($url, $default_headers = array(), $close = FALSE) {
  $headers = array();
  $type = substr($url, 0, 5);
  if ($type == 'https') {
    $headers[CURLOPT_SSL_VERIFYPEER] = 0;
    $headers[CURLOPT_SSL_VERIFYHOST] = 0;
  }
  elseif (substr($type, 0, 4) != 'http') {
    $url = 'http://'. $url;
  }
  $headers[CURLOPT_URL] = $url;
  $headers[CURLOPT_RETURNTRANSFER] = TRUE;
  $headers[CURLOPT_CONNECTTIMEOUT] = 10;
  $headers[CURLOPT_TIMEOUT] = 30;
  $headers[CURLOPT_FOLLOWLOCATION] = TRUE;
  $cookie_file = getcwd() . '/gr.tmp';
  $headers[CURLOPT_COOKIEFILE] = $cookie_file;
  $headers[CURLOPT_COOKIEJAR] = $cookie_file;

  $headers = $default_headers + $headers;

  static $ch;
  if (empty($ch)) $ch = curl_init();
  curl_setopt_array($ch, $headers);
  $output = trim(curl_exec($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curl_info = curl_getinfo($ch);
  if ($code == 200) {
    if ($close) {
      curl_close($ch);
      $ch = NULL;
    }
    return $output;
  }
  else {
    die(curl_error($ch));
    curl_close($ch);
    $ch = NULL;
  }
}
