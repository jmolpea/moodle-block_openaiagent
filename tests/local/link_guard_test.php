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
 * Tests for the visitor link filter.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for {@see link_guard}.
 *
 * @covers \block_openaiagent\local\link_guard
 */
final class link_guard_test extends \advanced_testcase {
    /**
     * Links to this site and to the assistant's documents stay; invented ones lose their target.
     */
    public function test_clean(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $DB->insert_record('block_openaiagent_chunks', (object)[
            'courseid' => SITEID, 'blockinstanceid' => 7, 'contenthash' => sha1('x'), 'filename' => 'faq.md',
            'citable' => 1, 'chunkindex' => 0, 'content' => 'Becas: https://becas.example.org/convocatoria',
            'embedding' => null, 'embeddingmodel' => '', 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $site = $CFG->wwwroot . '/login/index.php';
        $reply = "Entra [aquí]($site). Becas: [convocatoria](https://becas.example.org/convocatoria). "
            . "Soporte: [soporte](https://northfield.example.edu/support) o [enlace](support_url).";
        $clean = link_guard::clean($reply, 7);

        $this->assertStringContainsString("[aquí]($site)", $clean);
        $this->assertStringContainsString('[convocatoria](https://becas.example.org/convocatoria)', $clean);
        $this->assertStringNotContainsString('northfield.example.edu', $clean);
        $this->assertStringNotContainsString('support_url', $clean);
        $this->assertStringContainsString('Soporte: soporte o enlace.', $clean);

        // Another block's documents do not vouch for a link.
        $this->assertStringNotContainsString('becas.example.org', link_guard::clean($reply, 8));
    }
}
