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
 * Admin setting holding a list of email addresses.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\admin;

use block_openaiagent\local\support_mailer;

/**
 * Text setting that accepts several email addresses and rejects bad ones on save.
 *
 * A malformed support address is not discovered when it is typed: it is
 * discovered weeks later, when a participant's escalation silently fails to
 * reach anybody. Validating at save time is the only cheap moment.
 */
class setting_emaillist extends \admin_setting_configtext {
    /**
     * Validate the submitted address list.
     *
     * @param string $data Submitted value.
     * @return bool|string True when valid, or an error message.
     */
    public function validate($data) {
        $data = trim((string)$data);
        if ($data === '') {
            // Empty is legitimate: it means the feature has no destination yet
            // and falls back to the plain support link.
            return true;
        }

        $addresses = support_mailer::parse_addresses($data);
        if (empty($addresses)) {
            return get_string('settings_support_email_invalid', 'block_openaiagent', $data);
        }

        $invalid = support_mailer::invalid_addresses($addresses);
        if (!empty($invalid)) {
            return get_string('settings_support_email_invalid', 'block_openaiagent', implode(', ', $invalid));
        }

        $rejected = support_mailer::disallowed_addresses($addresses);
        if (!empty($rejected)) {
            return get_string('settings_support_email_domain', 'block_openaiagent', implode(', ', $rejected));
        }

        return true;
    }
}
