<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Run the code checker from the web.
 *
 * @package    local_sqlgenerator
 * @copyright  2014 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/sqlgenerator/locallib.php');
$PAGE->set_context(context_system::instance());

//$mform = new local_sqlgenerator_form(new moodle_url('/local/sqlgenerator/'));

global $DB;
global $CFG;
$component = optional_param('component', '', PARAM_PATH);

$PAGE->set_url('/admin/sqlgenerator.php');

$mform = new local_sqlgenerator_form(new moodle_url('/local/sqlgenerator/'));
$output = $PAGE->get_renderer('local_sqlgenerator');
if ($data = $mform->get_data()) {
    /* component.sql is the name of the output file with the sql statements */
    if (isset($data->checkmorekeys)) {
        $plugin_tablenames = get_tablenames_from_plugins();
        // error_log(json_encode($plugin_tablenames));
        // exit();
        $keys = get_morekeys();

        print "<br/>SizeOf keys :" . sizeof($keys);
        print "<br/><br/><br/><br/>Rows in morekeys.xml with no matching plugin table";
        $nomatchcount = 0;
        $loopstep = 0;
        foreach ($keys as $key => $value) {
            $tablefound = false;
            $loopstep++;
            $name = get_field($value, 'NAME');
            if ($name == null) {
                continue;
            }
            $keytablename = (explode("_erd_", $name)[0]);

            foreach ($plugin_tablenames as $tablename) {
                if (strcasecmp($keytablename, $tablename) == 0) {
                    $tablefound = true;
                }
            }
            if ($tablefound == false) {
                $nomatchcount++;
                print "<br/>" . $keytablename;
            }
        }
        print "<br/>NoMatchCount:" . $nomatchcount;
        ;
    }
    if (isset($data->submitbutton)) {
        print "<br/>Generating:";
        generate_sql("placeholdercomponent", "create_tables.sql");
    }
    if (isset($data->writexml)) {
        write_xml();
        print "<br/>Writing XML completed:";

    }
}

function write_xml(array $tablestowrite=array()) {
    global $DB, $CFG;
    $dbmanager = $DB->get_manager();
    $plugins = getDirectoryTree();
    $fh = fopen('table_xml.html', 'w') or die("can't open file table_xml.html");
    fwrite($fh,"<pre>");
    foreach ($plugins as $plugin) {
        //$xmldb_file = new xmldb_file($plugin);
        $contents = file_get_contents($plugin);
        fwrite($fh,"<hr/>");
        $folder=rtrim($plugin,"install.xml");
        $folder=str_replace($CFG->dirroot,'',$folder);
        $contents ="<br/>".$folder."<br/>".htmlspecialchars($contents);
        fwrite($fh,($contents));            
    }   
     fwrite($fh,"</pre>");
    fclose($fh);
}

function get_tablenames_from_plugins() {
    global $DB, $CFG;
    $dbmanager = $DB->get_manager();
    $plugins = getDirectoryTree();
    $tablecount = 0;
    $plugintables = array();
    foreach ($plugins as $plugin) {
        $sqlarr = get_sqlarr($plugin, $dbmanager);
        foreach ($sqlarr as $sql) {
            $tablename = get_tablename($sql);
            if ($tablename > "") {
                $plugintables[] = $tablename;
                $tablecount++;
            }
        }
    }
    //var_dump($plugintables[52]);
    //exit();
    return $plugintables;
}

function get_sqlarr($plugin, $dbmanager) {
    $xmldb_file = new xmldb_file($plugin);
    if (!$xmldb_file->fileExists()) {
        throw new ddl_exception('ddlxmlfileerror', null, 'File does not exist');
    }
    $loaded = $xmldb_file->loadXMLStructure();
    $xmldb_structure = $xmldb_file->getStructure();
    $sqlarr = $dbmanager->generator->getCreateStructureSQL($xmldb_structure);
    return $sqlarr;
}

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();

function get_field($key, $field) {
//$key ='<KEY NAME="competency_modulecomp#competency" TYPE="foreign" FIELDS="competencyid" REFTABLE="competency" REFFIELDS="id" COMMENT="" />';
    $xml = simplexml_load_string($key);
    if ($xml <> null) {
        $arr = $xml->attributes();
        return $arr[$field];
    } else {
        return null;
    }
}

function generate_sql($component, $outputfile) {
    global $CFG;
    global $DB;

    $dbmanager = $DB->get_manager();
    $dbmanager->generator->foreign_keys = true;
    $plugins = getDirectoryTree();

    $fh = fopen($outputfile, 'w') or die("can't open file");
    fwrite($fh, "/* Moodle version " . $CFG->version . " Release " . $CFG->release . " SQL code */");
    $keys = get_morekeys();
    foreach ($plugins as $plugin) {
        $xmldb_file = new xmldb_file($plugin);
        if (!$xmldb_file->fileExists()) {
            throw new ddl_exception('ddlxmlfileerror', null, 'File does not exist');
        }
        $loaded = $xmldb_file->loadXMLStructure();
        $xmldb_structure = $xmldb_file->getStructure();
        $sqlarr = $dbmanager->generator->getCreateStructureSQL($xmldb_structure);
        foreach ($sqlarr as $sql) {
            $sql = str_replace("CREATE TABLE", ";\r CREATE TABLE", $sql);
            $engineloc = strpos($sql, 'ENGINE = InnoDB');
            $uptoengine = substr($sql, 0, $engineloc);
            $lastparenloc = strrpos($uptoengine, ")");
            $tablename = get_tablename($sql);
            $key = find_key_for_table($tablename, $keys);
            $keycount = count($key);
            if ($keycount > 0) {
                $keystring = "";
                foreach ($key as $value) {
                    $keystring .= $value;
                }
                $sql = substr_replace($sql, ',' . $keystring, $lastparenloc, 0);
            }
            fwrite($fh, $sql);
        }
    }


    fclose($fh);
    print "<p>Done </p>";
}

function find_key_for_table($tablename, $keys) {
    $foreignkeys = array();
    foreach ($keys as $key) {
        $keyname = get_field($key, "NAME");
        $keytablename = (explode("_erd_", $keyname)[0]);
        $field = get_field($key, 'FIELDS');
        $reffield = get_field($key, "REFFIELDS");
        $reftable = get_field($key, "REFTABLE");
        if (trim($keytablename) === trim($tablename)) {
            $comma = "";
            if (count($foreignkeys) > 0) {
                $comma = ",";
            }
            $foreignkeys[] = $comma . "CONSTRAINT " . $keyname . " FOREIGN KEY(" . $field . ") REFERENCES " . $reftable . "(" . $reffield . ")";
        }
    }
    //var_dump($keys);
    return $foreignkeys;
}

function get_morekeys() {
    $fkeys = fopen('morekeys.xml', 'r') or ( "cant open morekeys.xml file");
    $keys = array();
    if ($fkeys) {
        while (($line = fgets($fkeys)) !== false) {
            $keys[] = $line;
        }
        fclose($fkeys);
        return $keys;
    } else {
        echo "<br/>error opening the file morekeys.xml";
    }
}

function get_tablename($sql) {
    global $CFG;
    $ct = "CREATE TABLE";
    /* start point of CREATE TABLE */
    $ctloc = strpos($sql, $ct);
    $tablestart = $ctloc + strlen($ct);
    $tableend = strpos($sql, '(');
    $len = $tableend - $tablestart;
    $tablename = substr($sql, $tablestart, $len);
    return trim($tablename);
}

/** Loop through all folders and get a list of all install.xml files 
 * with full path
 */
function getDirectoryTree($sort = 0) {
    global $CFG;
    $dir = $CFG->dirroot;
    $items = glob($dir . '/*');
    for ($i = 0; $i < count($items); $i++) {
        if (is_dir($items[$i])) {
            $add = glob($items[$i] . '/*');
            $items = array_merge($items, $add);
        }
    }
    $installfiles = array();
    foreach ($items as $item) {
        if (preg_match("/install\.xml/", $item)) {
            $installfiles[] = $item;
        }
    }
    return $installfiles;
}
