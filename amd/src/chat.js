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
 * Native chat module for the Smart Tutor & Support AI block.
 *
 * Talks to the Moodle server-side orchestrator over web services. No API key
 * or secret ever reaches the browser.
 *
 * @module     block_openaiagent/chat
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {
    'use strict';

    /**
     * Chat controller.
     *
     * @param {Object} config Configuration object.
     */
    function ChatController(config) {
        this.blockId = config.blockid;
        this.courseId = config.courseid;
        this.strings = config.strings || {};
        this.avatarUrl = config.avatarurl || '';
        this.conversationId = 0;
        this.isOpen = false;
        this.sending = false;
        this.loaded = false;
        this.typingIndicator = null;

        this.initElements();
        this.bindEvents();
    }

    /** Initialize DOM elements. */
    ChatController.prototype.initElements = function() {
        this.container = document.querySelector('[data-blockid="' + this.blockId + '"]');
        if (!this.container) {
            return;
        }
        this.triggerBtn = document.getElementById('openaiagent-trigger-' + this.blockId);
        this.modal = document.getElementById('openaiagent-modal-' + this.blockId);
        this.closeBtn = this.modal ? this.modal.querySelector('.openaiagent-close') : null;
        this.newChatBtn = this.modal ? this.modal.querySelector('.openaiagent-new-chat') : null;
        this.messagesContainer = document.getElementById('openaiagent-messages-' + this.blockId);
        this.input = document.getElementById('openaiagent-input-' + this.blockId);
        this.sendBtn = document.getElementById('openaiagent-send-' + this.blockId);
    };

    /** Bind event listeners. */
    ChatController.prototype.bindEvents = function() {
        var self = this;

        if (this.triggerBtn) {
            this.triggerBtn.addEventListener('click', function() {
                self.openChat();
            });
        }
        if (this.closeBtn) {
            this.closeBtn.addEventListener('click', function() {
                self.closeChat();
            });
        }
        if (this.newChatBtn) {
            this.newChatBtn.addEventListener('click', function() {
                self.startNewConversation();
            });
        }
        if (this.input) {
            this.input.addEventListener('input', function() {
                self.handleInputChange();
            });
            this.input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });
        }
        if (this.sendBtn) {
            this.sendBtn.addEventListener('click', function() {
                self.sendMessage();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && self.isOpen) {
                self.closeChat();
            }
        });
    };

    /** Open the chat modal. */
    ChatController.prototype.openChat = function() {
        if (!this.modal) {
            return;
        }
        this.modal.classList.add('is-open');
        this.modal.setAttribute('aria-hidden', 'false');
        this.isOpen = true;

        var self = this;
        if (this.input) {
            setTimeout(function() {
                self.input.focus();
            }, 100);
        }
        if (!this.loaded) {
            this.loadHistory();
        }
    };

    /** Close the chat modal. */
    ChatController.prototype.closeChat = function() {
        if (!this.modal) {
            return;
        }
        this.modal.classList.remove('is-open');
        this.modal.setAttribute('aria-hidden', 'true');
        this.isOpen = false;
    };

    /** Load any existing conversation history. */
    ChatController.prototype.loadHistory = function() {
        var self = this;
        this.loaded = true;

        Ajax.call([{
            methodname: 'block_openaiagent_get_conversation',
            args: {courseid: this.courseId, conversationid: 0, blockid: this.blockId}
        }])[0].then(function(result) {
            self.conversationId = result.conversationid || 0;
            result.messages.forEach(function(msg) {
                // Assistant history arrives as sanitized HTML; user messages as text.
                if (msg.role === 'user') {
                    self.addMessage(msg.content, 'user', false);
                } else {
                    self.addMessage(msg.content, 'assistant', true);
                }
            });
            self.renderActions(result.actions);
            return result;
        }).catch(function() {
            // A missing history is not fatal; the user can simply start chatting.
            return null;
        });
    };

    /** Handle input field changes (enable send, auto-grow). */
    ChatController.prototype.handleInputChange = function() {
        if (this.sendBtn) {
            this.sendBtn.disabled = !this.input.value.trim() || this.sending;
        }
        this.input.style.height = 'auto';
        this.input.style.height = Math.min(this.input.scrollHeight, 120) + 'px';
    };

    /** Send the current message to the orchestrator. */
    ChatController.prototype.sendMessage = function() {
        var self = this;
        var message = this.input ? this.input.value.trim() : '';
        if (!message || this.sending) {
            return;
        }

        this.addMessage(message, 'user');
        this.input.value = '';
        this.handleInputChange();
        this.setSending(true);
        this.showTyping();

        Ajax.call([{
            methodname: 'block_openaiagent_send_message',
            args: {
                courseid: this.courseId,
                message: message,
                conversationid: this.conversationId,
                blockid: this.blockId
            }
        }])[0].then(function(result) {
            self.hideTyping();
            self.setSending(false);
            if (result.conversationid) {
                self.conversationId = result.conversationid;
            }
            self.addMessage(result.reply, 'assistant', true);
            self.renderActions(result.actions);
            self.focusInput();
            return result;
        }).catch(function(error) {
            self.hideTyping();
            self.setSending(false);
            self.showAssistantError();
            Notification.exception(error);
        });
    };

    /** Return focus to the input once it is re-enabled. */
    ChatController.prototype.focusInput = function() {
        if (this.input && this.isOpen) {
            this.input.focus();
        }
    };

    /** Start a fresh conversation. */
    ChatController.prototype.startNewConversation = function() {
        var self = this;
        if (this.sending) {
            return;
        }
        Ajax.call([{
            methodname: 'block_openaiagent_reset_conversation',
            args: {courseid: this.courseId, blockid: this.blockId}
        }])[0].then(function() {
            self.conversationId = 0;
            if (self.messagesContainer) {
                self.messagesContainer.innerHTML = '';
            }
            if (self.input) {
                self.input.focus();
            }
            return true;
        }).catch(function(error) {
            Notification.exception(error);
        });
    };

    /**
     * Toggle the sending state.
     *
     * @param {Boolean} sending True while a request is in flight.
     */
    ChatController.prototype.setSending = function(sending) {
        this.sending = sending;
        if (this.sendBtn) {
            this.sendBtn.disabled = sending || !this.input.value.trim();
        }
        if (this.input) {
            this.input.disabled = sending;
        }
    };

    /**
     * Build the small assistant avatar shown next to assistant bubbles.
     *
     * Uses the configured avatar image when available, otherwise the default
     * sparkle icon. User messages carry no avatar: in a narrow chat panel the
     * extra circle only steals width from the text.
     *
     * @return {HTMLElement} Avatar element.
     */
    ChatController.prototype.buildAssistantAvatar = function() {
        var avatar = document.createElement('div');
        avatar.className = 'openaiagent-message-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        if (this.avatarUrl) {
            var img = document.createElement('img');
            img.src = this.avatarUrl;
            img.alt = '';
            img.className = 'openaiagent-message-avatar-img';
            avatar.appendChild(img);
        } else {
            avatar.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"' +
                ' viewBox="0 0 24 24" fill="none"><path d="M12 2L13.09 8.26L19 6L14.74 10.74L21 12' +
                'L14.74 13.26L19 18L13.09 15.74L12 22L10.91 15.74L5 18L9.26 13.26L3 12L9.26 10.74' +
                'L5 6L10.91 8.26L12 2Z" fill="white"/></svg>';
        }
        return avatar;
    };

    /**
     * Append a message bubble.
     *
     * @param {String} text Message text (plain text, or sanitized HTML when isHtml).
     * @param {String} type 'user' or 'assistant'.
     * @param {Boolean} isHtml True when the text is server-sanitized HTML
     *                  (assistant replies rendered through Moodle format_text).
     */
    ChatController.prototype.addMessage = function(text, type, isHtml) {
        if (!this.messagesContainer || !text) {
            return;
        }
        var messageDiv = document.createElement('div');
        messageDiv.className = 'openaiagent-message openaiagent-message-' + type;

        var content = document.createElement('div');
        content.className = 'openaiagent-message-content';
        if (isHtml) {
            // Safe: this HTML was produced server-side by format_text(), which
            // converts the assistant's Markdown and strips unsafe markup.
            content.innerHTML = text;
        } else {
            var p = document.createElement('p');
            p.textContent = text;
            content.appendChild(p);
        }

        if (type === 'assistant') {
            messageDiv.appendChild(this.buildAssistantAvatar());
        }
        messageDiv.appendChild(content);
        this.messagesContainer.appendChild(messageDiv);
        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
    };

    /**
     * Render the interactive actions that came with a reply.
     *
     * Actions are dispatched by type through a small registry. A type this
     * script does not know is skipped in silence, so a browser holding an older
     * cached copy of this file keeps working when new action types are added
     * server-side instead of throwing on every reply.
     *
     * @param {Array} actions Action descriptors from the web service.
     */
    ChatController.prototype.renderActions = function(actions) {
        var self = this;
        if (!Array.isArray(actions) || !this.messagesContainer) {
            return;
        }
        actions.forEach(function(action) {
            var renderer = self.actionRenderers[action.type];
            if (!renderer) {
                return;
            }
            var payload;
            try {
                payload = JSON.parse(action.payload || '{}');
            } catch (e) {
                return;
            }
            renderer.call(self, action, payload);
        });
    };

    /**
     * Card asking the participant to confirm a support request.
     *
     * @param {Object} action Action descriptor.
     * @param {Object} payload Decoded payload.
     */
    ChatController.prototype.renderSupportCard = function(action, payload) {
        var self = this;
        // Never two cards for the same draft: replies and history reloads can
        // both deliver the same pending action.
        var existing = this.messagesContainer.querySelector('[data-supportdraft="' + action.id + '"]');
        if (existing) {
            return;
        }

        var card = document.createElement('div');
        card.className = 'openaiagent-action-card';
        card.setAttribute('data-supportdraft', String(action.id));

        var title = document.createElement('p');
        title.className = 'openaiagent-action-title';
        title.textContent = payload.title || '';
        card.appendChild(title);

        // textContent throughout: this text was written by a language model and
        // is never treated as markup.
        var summary = document.createElement('p');
        summary.className = 'openaiagent-action-summary';
        summary.textContent = payload.summary || '';
        card.appendChild(summary);

        var notice = document.createElement('p');
        notice.className = 'openaiagent-action-privacy';
        notice.textContent = payload.privacynotice || '';
        if (payload.privacyemail) {
            // The sentence that tells them where the reply lands, built as an
            // element rather than markup inside the string.
            var email = document.createElement('strong');
            email.className = 'openaiagent-action-privacy-email';
            email.textContent = payload.privacyemail;
            notice.appendChild(document.createTextNode(' '));
            notice.appendChild(email);
        }
        card.appendChild(notice);

        var buttons = document.createElement('div');
        buttons.className = 'openaiagent-action-buttons';

        var confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'btn btn-primary openaiagent-action-confirm';
        confirm.textContent = payload.confirmlabel || action.label || '';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-secondary openaiagent-action-cancel';
        cancel.textContent = payload.cancellabel || '';

        buttons.appendChild(confirm);
        buttons.appendChild(cancel);
        card.appendChild(buttons);

        confirm.addEventListener('click', function() {
            self.answerSupportCard(card, action.id, payload.token, true);
        });
        cancel.addEventListener('click', function() {
            self.answerSupportCard(card, action.id, payload.token, false);
        });

        this.messagesContainer.appendChild(card);
        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
    };

    /**
     * Send the participant's decision and replace the card with the outcome.
     *
     * @param {Element} card The card element.
     * @param {Number} draftid Draft id.
     * @param {String} token Confirmation token.
     * @param {Boolean} confirmed Whether they confirmed.
     */
    ChatController.prototype.answerSupportCard = function(card, draftid, token, confirmed) {
        var self = this;
        var buttons = card.querySelectorAll('button');
        // Disabled immediately: the request is not instant and a second click
        // would be a second attempt at a token that is only good once.
        Array.prototype.forEach.call(buttons, function(button) {
            button.disabled = true;
        });

        Ajax.call([{
            methodname: 'block_openaiagent_confirm_support_request',
            args: {
                courseid: this.courseId,
                draftid: draftid,
                token: token || '',
                confirm: confirmed
            }
        }])[0].then(function(result) {
            card.parentNode.removeChild(card);
            self.addMessage(result.message, 'assistant');
            return result;
        }).catch(function(error) {
            Array.prototype.forEach.call(buttons, function(button) {
                button.disabled = false;
            });
            Notification.exception(error);
        });
    };

    /** Renderers by action type. */
    ChatController.prototype.actionRenderers = {
        confirm_support: ChatController.prototype.renderSupportCard
    };

    /** Show a generic assistant error bubble. */
    ChatController.prototype.showAssistantError = function() {
        var self = this;
        if (this.strings.error_openai_failed) {
            this.addMessage(this.strings.error_openai_failed, 'assistant');
            return;
        }
        Str.get_string('error_openai_failed', 'block_openaiagent').then(function(s) {
            self.addMessage(s, 'assistant');
            return s;
        }).catch(function() {
            return null;
        });
    };

    /**
     * Show a typing indicator bubble while the assistant prepares its answer.
     *
     * Rendered as a normal assistant message with three animated dots, so the
     * chat never looks frozen: the user sees the assistant "writing".
     */
    ChatController.prototype.showTyping = function() {
        if (!this.messagesContainer || this.typingIndicator) {
            return;
        }
        var row = document.createElement('div');
        row.className = 'openaiagent-message openaiagent-message-assistant openaiagent-typing';

        var content = document.createElement('div');
        content.className = 'openaiagent-message-content';
        var dots = document.createElement('div');
        dots.className = 'openaiagent-typing-dots';
        dots.setAttribute('aria-hidden', 'true');
        for (var i = 0; i < 3; i++) {
            dots.appendChild(document.createElement('span'));
        }
        var srlabel = document.createElement('span');
        srlabel.className = 'sr-only';
        srlabel.textContent = this.strings.thinking || '...';
        content.appendChild(dots);
        content.appendChild(srlabel);

        row.appendChild(this.buildAssistantAvatar());
        row.appendChild(content);
        this.messagesContainer.appendChild(row);
        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
        this.typingIndicator = row;
    };

    /** Remove the typing indicator bubble. */
    ChatController.prototype.hideTyping = function() {
        if (this.typingIndicator && this.typingIndicator.parentNode) {
            this.typingIndicator.parentNode.removeChild(this.typingIndicator);
        }
        this.typingIndicator = null;
    };

    return {
        /**
         * Initialize the chat module.
         *
         * @param {Object} config Configuration object.
         */
        init: function(config) {
            new ChatController(config);
        }
    };
});
