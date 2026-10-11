const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert/strict');

let requested, eventSource, timerDelay, touches = 0;
const sandbox = {
    window: {
        __chatConfig: { mercureUrl: 'https://portal.test/.well-known/mercure', currentUserId: 11 },
        location: { href: 'https://portal.test/dashboard' },
    },
    document: { readyState: 'loading', addEventListener() {} },
    URL,
    fetch: async (url, options) => {
        requested = { url, options };
        return { ok: true, json: async () => ({ topic: '/chat/user/11' }) };
    },
    EventSource: class {
        constructor(url, options) {
            this.url = url;
            this.options = options;
            eventSource = this;
        }
        close() { this.closed = true; }
    },
    setTimeout: (fn, delay) => { timerDelay = delay; return 1; },
    clearTimeout() {},
};

const file = path.resolve(__dirname, '../../public/front_end/assets/static/js/chat.js');
let source = fs.readFileSync(file, 'utf8');
source = source.replace(/\}\)\(\);\s*$/, 'window.testChat={state,els,subscribeUserTopic};})();');
vm.runInNewContext(source, sandbox);
const api = sandbox.window.testChat;
api.state.currentRoomId = 7;
api.els.messagesArea = { querySelectorAll: () => { touches++; return []; } };
api.subscribeUserTopic();

setImmediate(() => {
    assert.equal(requested.url, '/api/chat/subscription');
    assert.equal(requested.options.credentials, 'same-origin');
    assert.equal(eventSource.options.withCredentials, true);
    assert.equal(eventSource.url.searchParams.get('topic'), '/chat/user/11');
    assert.equal(timerDelay, 50 * 60 * 1000);
    eventSource.onmessage({ data: JSON.stringify({ type: 'read', room_id: 8, user_id: 22 }) });
    assert.equal(touches, 0);
    eventSource.onmessage({ data: JSON.stringify({ type: 'read', room_id: 7, user_id: 22 }) });
    assert.equal(touches, 1);
    eventSource.onerror();
    assert.equal(eventSource.closed, true);
    assert.equal(timerDelay, 5000);
    console.log('PASS: subscriber credentials, personal topic, room isolation, JWT refresh and retry');
});
