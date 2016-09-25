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
 * A scheduled task for scripted database integrations.
 *
 * @package    local_scheduledtasks - nightly
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_nightly\task;

/**
 * A scheduled task for scripted database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nightly extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('nightly', 'local_nightly');
    }

    /**
     * Run sync.
     */
    public function execute() {

        /* Category Creation script *
         * ======================== */

        global $CFG, $DB;
        require_once($CFG->libdir . "/coursecatlib.php");
        $data = array();

        /* Table name: This table should contain id:category name:category parent (in this case
         * using a unique idnumber as parent->id is not necessarily known):category idnumber (as
         * a unique identifier). */
        $table = 'usr_category_creation';
        $sql = 'SELECT * FROM '.$table;
        $params = null;
        // Read database table.
        $rs = $DB->get_records_sql($sql, $params);

        // Loop through all records found.
        foreach ($rs as $category) {
            // Only run through code if the category->idnumber is set and doesn't already exist.
            if (isset($category->idnumber) && !$DB->record_exists('course_categories',array('idnumber' => $category->idnumber))) {
                // Set all the $data
                // Error trap - category name is required and set $data['name'].
                if (isset($category->name)) {
                    $data['name'] = $category->name;
                } else {
                    print_r($category);
                    echo 'Category Name required';
                    break;
                }
                // Default $parent values as Top category, so if none set new category defaults to top level.
                $parent = $DB->get_record('course_categories', array('id' => 1));
                $parent->id = 0;
                $parent->visible = 1;
                $parent->depth = 0;
                $parent->path = '';
                // Set $data['parent'] by fetching parent->id based on unique parent category idnumber, if set.
                if (!$category->parent == '') {
                    // Check if the parent category already exists - based on unique idnumber.
                    if ($DB->record_exists('course_categories', array('idnumber' => $category->parent))) {
                        // Fetch that parent category details.
                        $parent = $DB->get_record('course_categories', array('idnumber' => $category->parent));
                    }
                }
                // Set $data['parent'] as the id of the parent category and depth as parent +1.
                $data['parent'] = $parent->id;
                $data['depth'] = $parent->depth + 1;

                // Set $data['idnumber'] - we've already checked it's unique through the conditional above.
                $data['idnumber'] = $category->idnumber;
                // Set $data['description'] But this is always empty in our case as it is not being stored.
                $data['description'] = '';
                $data['descriptionformat'] = FORMAT_MOODLE;

                // Create a category that inherits visibility from parent.
                $data['visible'] = $parent->visible;
                // In case parent is hidden, when it changes visibility this new subcategory will automatically become visible too.
                $data['visibleold'] = 1;
                // Set default sort order as 0 and time modified as now.
                $data['sortorder'] = 0;
                $data['timemodified'] = time();

                // Set new category id by inserting the data created above.
                $data['id'] = $DB->insert_record('course_categories', $data);
                // Update path (only possible after we know the category id.
                $path = $parent->path . '/' . $data['id'];
                $DB->set_field('course_categories', 'path', $path, array('id' => $data['id']));
                // Use core function to adjust sort order as appropriate.
                fix_course_sortorder();

                // Set values to write to mdl_context table.
                $record['contextlevel'] = 40;
                $record['instanceid']   = $data['id'];
                $record['depth']        = 0; //set as default
                $record['path']         = null; // Not known before insert.
                $parentpath = '/1';
                $parentdepth = 0;
                // Adjust values as approriate to account for parent context - if there is one.
                if($data['parent'] != 0) {
                    // SQL to find parent context.
                    $sql = "SELECT * FROM mdl_context WHERE `contextlevel` = 40 AND `instanceid` = ".$data['parent'];
                    $params = null;
                    $parentcontext = $DB->get_record_sql($sql, $params);
                    $parentpath = $parentcontext->path;
                    $parentdepth = $parentcontext->depth;
                }
                // Write deafult data and find record id.
                $record['id'] = $DB->insert_record('context', $record);
                // Adjust values for parent contexts now we have record id.
                $record['path'] = $parentpath.'/'.$record['id'];
                $record['depth'] = $parentdepth + 1;
                $DB->update_record('context', $record);

            } else {
                echo 'Category idnumber must exist and be unique';
            }
        }
        // Context maintenance stuff.
        \context_helper::cleanup_instances();
        mtrace(' Cleaned up context instances');
        \context_helper::build_all_paths(false);
        // If you suspect that the context paths are somehow corrupt
        // replace the line below with: context_helper::build_all_paths(true).

    }
}
