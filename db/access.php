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
 * Capability definitions for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'block/openaiagent:addinstance' => [
        'riskbitmask' => RISK_SPAM | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ],
    'block/openaiagent:myaddinstance' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    'block/openaiagent:use' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            // The 'user' (authenticated user) archetype is needed so the block also works on
            // site-level pages (site home, category pages, dashboard) where
            // students have no course role. Guests remain excluded.
            'user' => CAP_ALLOW,
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Manage the per-course assistant configuration.
    'block/openaiagent:managecourseconfig' => [
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],
    // Set the destination address of the support escalation email.
    //
    // Deliberately NOT part of managecourseconfig, which every editing teacher
    // holds by default. That email carries the participant's full name, address,
    // course, groups and optionally the conversation transcript, so a free-text
    // recipient in the course form would let any editing teacher redirect a
    // steady flow of their students' personal data to a private mailbox --
    // silently, and looking exactly like normal operation. Everyone else still
    // edits the subject, the body and the on/off switch, and can point the
    // course at {course_teachers}, which resolves server-side and cannot reach
    // outside the institution.
    'block/openaiagent:managesupportrecipient' => [
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Raise a support request from the assistant chat.
    'block/openaiagent:requestsupport' => [
        'riskbitmask' => RISK_SPAM,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Raise a support request from a category or site assistant.
    //
    // Separate from requestsupport, which is course-scoped and held through
    // course roles: outside a course most participants have no role at all, so
    // the authenticated user archetype is what lets them reach support there.
    // Being new, it is granted on upgrade without touching existing roles, and a
    // site can still prohibit it on a category.
    'block/openaiagent:requestplatformsupport' => [
        'riskbitmask' => RISK_SPAM,
        'captype' => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes' => [
            'user' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Use a category or site assistant without logging in, once an administrator
    // has opened that block to visitors. Held by the guest role, which is also
    // the role of users who are not logged in; a site can prohibit it on a
    // category. Never checked for a course assistant.
    'block/openaiagent:usepublic' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes' => [
            'guest' => CAP_ALLOW,
        ],
    ],
    // Open a category or site assistant to visitors who are not logged in.
    // Each visitor message is paid with the site's provider key, so this is
    // kept to managers and deliberately not cloned from any editing capability.
    'block/openaiagent:managepublicaccess' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Manage global plugin configuration (secrets, models, endpoints).
    'block/openaiagent:manageglobalconfig' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // View conversation/message logs.
    'block/openaiagent:viewlogs' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Run connection/test tools (OpenAI, MCP).
    'block/openaiagent:testconnection' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
