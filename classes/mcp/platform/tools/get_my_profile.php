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
 * Platform tool: the participant's own account basics.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;

/**
 * First name, language, timezone, how they sign in and whether Moodle manages their password.
 *
 * The sign-in method is what lets the assistant answer password questions
 * properly: someone who signs in through single sign-on or LDAP must be sent to
 * their institution, not to Moodle's password reset form, which would not help.
 * No email, username or other profile field is returned.
 */
class get_my_profile extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.get_my_profile';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'Return the participant\'s own account basics: first name, interface language, timezone, '
            . 'last access to the site, how they sign in (auth_method) and whether their password is managed '
            . 'by Moodle. Use it for login and password questions: when password_managed_by_moodle is false, '
            . 'send them to password_help_url or their institution, never to Moodle\'s reset form. '
            . 'No email, username or other profile field is available.';
    }

    /**
     * Input schema.
     *
     * @return array
     */
    public function input_schema(): array {
        return self::schema();
    }

    /**
     * Run the tool.
     *
     * @param array $input Arguments (none).
     * @param scope $scope Turn scope.
     * @return array
     */
    public function execute(array $input, scope $scope): array {
        global $CFG;

        $user = \core_user::get_user($scope->userid, 'id, firstname, lang, timezone, auth, lastaccess', MUST_EXIST);
        $auth = get_auth_plugin($user->auth);

        $languages = get_string_manager()->get_list_of_translations();
        $managed = (bool)$auth->can_change_password() && !$auth->change_password_url();

        // Where to go for a password problem: Moodle's own change page when it
        // manages the password, the auth plugin's page when it names one, and
        // the site's configured "forgotten password" address otherwise.
        $helpurl = null;
        if ($managed) {
            $helpurl = (new \moodle_url('/login/change_password.php'))->out(false);
        } else if ($external = $auth->change_password_url()) {
            $helpurl = $external instanceof \moodle_url ? $external->out(false) : (string)$external;
        } else if (!empty($CFG->forgottenpasswordurl)) {
            $helpurl = (string)$CFG->forgottenpasswordurl;
        }

        return [
            'firstname' => (string)$user->firstname,
            'language' => $languages[$user->lang] ?? (string)$user->lang,
            'timezone' => \core_date::get_user_timezone($user),
            'last_site_access' => self::date((int)$user->lastaccess),
            'auth_method' => $auth->get_title(),
            'password_managed_by_moodle' => $managed,
            'can_reset_password_in_moodle' => (bool)$auth->can_reset_password(),
            'password_help_url' => $helpurl,
        ];
    }
}
