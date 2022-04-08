<?php
$debug = false;
$uri = $_SERVER['REQUEST_URI'];
include('env.php');
include('../wp-config.php');
$formNum = $_GET['form'];
echo <<<HEAD
    <html>
    <head>
    <title>eForms $formNum</title>
    <style>
        th    { border: 1px solid gray; padding: 0 .3em; vertical-align: bottom; }
        td    { border: 1px solid gray; padding: 0 .3em; }
        table { border-collapse: collapse; }
        .c    { text-align: center; }
        .l    { text-align: left; }
        .r    { text-align: right; }
    </style>
    </head>
    <body>
        <p style="text-align:right;">View <a href="/ef/zoom/">Zoom history</a></p>
HEAD;

// Connect to the database
$mysqli = new mysqli('localhost', DB_USER, DB_PASSWORD, DB_NAME);

// Navigation: Display list of forms??
if (!$formNum) { //If no form number was given, display some choices
    echo "<p>Choose a form:</p>\n";
    $result = $mysqli->query("SELECT * FROM {$pfx}fsq_form WHERE 1 ORDER BY id DESC");
    $forms = $result->fetch_all(MYSQLI_ASSOC); 
    echo "<table><tr><th>Form</th><th>Signups</th><th>Registration Data</th><th>Original</th></tr>\n";
    foreach ($forms as $form) {
        $result = $mysqli->query("SELECT COUNT(*) AS count FROM {$pfx}fsq_payment WHERE form_id=$form[id]")->fetch_assoc();
        echo "<tr>
            <td class='r'>$form[id]</td>
            <td class='c'>$result[count]</td>
            <td><a href='$uri?form=$form[id]'>$form[name]</a></td>
            <td><a href='/eforms/$form[id]' target='_blank'>Form $form[id]</a></td>\n</tr>\n";
    }
    echo "</table>\n";
    die();
} else { //If we're working on a form, give option to see other forms
}
    

/***********************************************
 * If we got this far, we're displaying a form *
 * First, find out about the form              *
 ***********************************************/


//*** Get and process the form iteself (not yet the registrations) ***
$formQ = $mysqli->query("SELECT * FROM {$pfx}fsq_form WHERE id=$formNum");
$row = $formQ->fetch_assoc();

// parse out the arrays
$mcqAnswers = unserialize($row['mcq']); 
$form['layout']   = unserialize($row['layout']); 
$form['mcq']      = unserialize($row['mcq']); 
$form['freetype'] = unserialize($row['freetype']); 
$form['design']   = unserialize($row['design']); 
$form['pinfo']    = unserialize($row['pinfo']); 
if ($formNum == 85) {
    $form['mcq'][39]['settings']['options'][2]['label'] = 'single';
    $form['mcq'][41]['settings']['options'][0]['label'] = 'ensuite single';
}

//Display the name of the form and some navigation
echo "<h2>$row[name]</h2>\n"; 
echo "<p><a href='$_SERVER[SCRIPT_NAME]'>Go to list of forms</a> or ";
echo "<a href='/eforms/$formNum' target='_blank'>View form $formNum</a></p>\n";

if ($debug) {
    foreach($form['mcq'] as $qi => $question) {
        echo "<br>$qi: ";
        foreach($question['settings']['options'] as $ai => $answer)
            echo "$ai $answer[label],";
    }
    //$td = $form[$type][$question]['settings']['options'][$answerNum]['label'];
}


// Get Fields by Layout, and remove the ones we want as first
$specialColumns  = ['f_name','l_name','email'];
$elements = [];
foreach($form['layout'] as $tab)
    $elements = [...$elements, ...$tab['elements']]; //All the fields in the form
$fieldsByLayout = []; $options = [];
if ($debug) echo "<table><tr>";
foreach($elements as $layoutIndex => $element) {
    $type   = $element['type'];
    $m_type = $element['m_type'];
    if (false === array_search($type, $specialColumns) & $type !== 'embed' & $m_type !=='design' & $type!=='mathematical') {
        $key    = $element['key'];
        $title  = $form[$m_type][$key]['title']
                  . ($form[$m_type][$key]['subtitle'] ? ' ~ '.$form[$m_type][$key]['subtitle'] : '');
        $fieldsByLayout[$layoutIndex]  = ['m_type' => $m_type, 'type' => $type, 'key' => $key, 'title' => $title];
    }
    if ($m_type === 'mcq') {
        if ($type === 'slider') {
            $options[$key] = 'slider';
        } else {
            foreach ($form[$m_type][$key]['settings']['options'] as $optIndex => $option)
                $options[$key][$optIndex] = ['title' => $option['label'], 'count' => 0];
        }
    }
    if ($debug) {
        echo "<td>$key<br><b>$title</b><br>$type<br><b>$m_type</b><br>";
        if (array_key_exists($key, $options))
            echo var_export($options[$key],true);
        echo "</td>";
    }
}
if ($debug) echo "</tr></table>";
$columns  = ['f_name','l_name','email','amount','date'];
//echo "<pre>".var_export($fieldsByLayout,true)."</pre>";


/*
// *** Display sorting options ***
echo <<<FORMSTART
<form action="$_SERVER[REQUEST_URI]" method="GET">
    <input type="submit" value="GATE GATE">
    <table>
    <thead><tr><th>Sort</th><th>Show</td><th>Field</th></tr></thead>
    <tbody>
FORMSTART;
foreach($fieldsByKey as $q => $option) {
    echo <<<FORMROW
        <tr>
            <td><input type='radio' name='sortby'></td><td><input type='checkbox' name='include'></td><td>$option[title] ($q)</td>
        </tr>
FORMROW;
}
echo <<<FORMEND
    </tbody>
    </table>
</form>
FORMEND;
*/


/***********************************************
 *           OK, show that data!               *
 ***********************************************/


//*** Show the signups - Most recent first ***
$mainQuery = "SELECT f_name,l_name,email,amount,`{$pfx}fsq_payment`.`date`,freetype,mcq 
            FROM `{$pfx}fsq_payment` 
            LEFT JOIN {$pfx}fsq_data 
                ON `{$pfx}fsq_payment`.`data_id`=`{$pfx}fsq_data`.`id` 
            WHERE `{$pfx}fsq_payment`.`form_id`=$formNum ";

echo "<h2>Most Recent</h2>\n";
$result  = $mysqli->query($mainQuery. "ORDER BY `{$pfx}fsq_payment`.`date` DESC");
$signups = $result->fetch_all(MYSQLI_ASSOC); 
makeTable($signups, -1);

echo "<h2>Alphabetical</h2>\n";
$result  = $mysqli->query($mainQuery . "ORDER BY f_name ASC, l_name ASC");
$signups = $result->fetch_all(MYSQLI_ASSOC); 
makeTable($signups, 1, false);



function makeTable($signups, $inc = 1, $count = true) {
    global $form, $fieldsByLayout, $fieldk, $columns, $formNum, $options;

    // Table header
    echo "<table>\n<thead>\n<tr>\n";
    echo "<th> </th><th>First</th><th>Last</th><th>Email</th><th>Paid</th><th>Date</th>";
    foreach ($fieldsByLayout as $f)
        echo "<th>$f[title]</th>";
    echo "</tr>\n</thead>\n";

    // Table data
    $x = ($inc < 0) ? count($signups) : 1;
    foreach ($signups as $i => $s) {
        echo "<tr><td>$x</td>"; $x = $x + $inc;
        $s['mcq']      = unserialize($s['mcq']);
        $s['freetype'] = unserialize($s['freetype']);
        $s['pinfo']    = unserialize($s['pinfo']);
        foreach ($columns as $c)
            echo '<td>'.$s[$c].'</td>';
        foreach ($fieldsByLayout as $f) {
            $m_type   = $f['m_type'];
            $question = $f['key'];
            switch ($m_type) {
                case 'mcq':
                    if ($options[$question] === 'slider') {
                        $td = $s[$m_type][$question]['value'];
                    } else {
                        $answerNum = $s[$m_type][$question]['options'][0];
                        $td = $options[$question][$answerNum]['title'];
                        if ($count) $options[$question][$answerNum]['count']++; 
                        if (!$td & $formNum==85) {
                            $td = $answerNum;
                        }
                    }
                    break;
                case 'freetype':
                    $td = $s[$m_type][$question]['value'];
                    break;
                case 'pinfo':
                    $answerNum = $s[$m_type][$question]['options'][0];
                    $td = $form[$m_type][$question]['settings']['options'][$answerNum]['label'];
                    break;
                default:
                    $td = $m_type;
            }
            echo "<td>$td</td>";
        }
        echo "</tr>\n";
    }
    echo "<tr><th> </th>";
    foreach ($columns as $c)
        echo '<th> </th>';
    foreach ($fieldsByLayout as $f) {
        $m_type   = $f['m_type'];
        $question = $f['key'];
        if ($m_type === 'mcq') {
            $td = ''; $color='green';
            foreach ($options[$question] as $o) {
                $td .= "$o[count] - <span style='color:$color;'>$o[title]</span><br>";
                $color = ($color == 'green') ? 'brown' : 'green';
            }
        } else {
            $td = '';
        }
        echo "<th class='l'>$td</th>";
    }
    echo "</tr>\n";
    echo "</tbody>\n</table>\n";
//echo "<pre>".var_export($options,true)."</pre>";
    
}



?>
</body>
</html>
