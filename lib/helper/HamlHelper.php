<?php

function haml_echo($text, $indent = 0)
{
  $lines = explode("\n", $text);
  if (count($lines) > 1)
  {
    foreach ($lines as $key => $line) {
      if (trim($line) == '') continue;
      $lines[$key] = str_pad($line, strlen($line)+$indent, ' ', STR_PAD_LEFT);
    }
    $text = implode("\n", $lines)."\n";
  }

  echo $text;
}

function haml_preserve($text)
{
  return str_replace(array("\n", "\r"), array('&#x000A;', ''), $text);
}

function haml_cycle($values, $name = 'default', $delimiter = ',')
{
  static $cycle_vars;

  $name = (empty($name))?'default':$name;

  if (isset($cycle_vars[$name]['values']) && $cycle_vars[$name]['values'] != $values ) {
    $cycle_vars[$name]['index'] = 0;
  }
  $cycle_vars[$name]['values'] = $values;
  $cycle_vars[$name]['delimiter'] = (empty($delimiter))?',':$delimiter;

  if (is_array($cycle_vars[$name]['values'])) {
    $cycle_array = $cycle_vars[$name]['values'];
  } else {
    $cycle_array = explode($cycle_vars[$name]['delimiter'], $cycle_vars[$name]['values']);
  }

  if (!isset($cycle_vars[$name]['index'])) {
    $cycle_vars[$name]['index'] = 0;
  }

  $retval = $cycle_array[$cycle_vars[$name]['index']];

  if ($cycle_vars[$name]['index'] >= count($cycle_array) - 1) {
    $cycle_vars[$name]['index'] = 0;
  } else {
    $cycle_vars[$name]['index']++;
  }

  return $retval;
}