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
 * Platform tool: how to get into the site and whom to contact.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;

/**
 * Log-in, self-registration, guest access, password recovery and site support, as configured.
 *
 * Answers "how do I create an account?", "I cannot log in" and "who do I ask?"
 * from the site's real settings. For a visitor, the site support page is the
 * way to reach a person, since the assistant's own escalation needs an account.
 */
class get_site_access_info extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.get_site_access_info';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'Return how to access this site as configured: login_url, whether new users can create their own '
            . 'account (self_registration and signup_url), whether guest access is offered, where to recover '
            . 'a forgotten password, and the site support page when it is available to this user. Use it for '
            . '"how do I create an account", "I cannot log in" and "who can help me".';
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
     * Visitors need this most.
     *
     * @return string[]
     */
    public function audiences(): array {
        return [scope::AUTHENTICATED, scope::VISITOR];
    }

    /**
     * Run the tool.
     *
     * @param array $input Arguments (none).
     * @param scope $scope Turn scope.
     * @return array
     */
    public function execute(array $input, scope $scope): array {
        global $CFG, $SITE;
        require_once($CFG->libdir . '/authlib.php');

        $signup = signup_is_enabled();

        $forgot = !empty($CFG->forgottenpasswordurl)
            ? (string)$CFG->forgottenpasswordurl
            : (new \moodle_url('/login/forgot_password.php'))->out(false);

        // Moodle's own rule for who may open the "Contact site support" form.
        $availability = (int)($CFG->supportavailability ?? CONTACT_SUPPORT_DISABLED);
        $cansupport = $availability === CONTACT_SUPPORT_ANYONE
            || ($availability === CONTACT_SUPPORT_AUTHENTICATED && !$scope->is_visitor());
        $supporturl = null;
        if (!empty($CFG->supportpage)) {
            $supporturl = (string)$CFG->supportpage;
        } else if ($cansupport) {
            $supporturl = (new \moodle_url('/user/contactsitesupport.php'))->out(false);
        }

        return [
            'site' => format_string($SITE->fullname),
            'logged_in' => !$scope->is_visitor(),
            'login_url' => get_login_url(),
            'self_registration' => (bool)$signup,
            'signup_url' => $signup ? (new \moodle_url('/login/signup.php'))->out(false) : null,
            'guest_access_offered' => !empty($CFG->guestloginbutton),
            'forgot_password_url' => $forgot,
            'support_url' => $supporturl,
        ];
    }
}
