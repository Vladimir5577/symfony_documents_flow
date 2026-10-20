const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sources = [];
const timers = new Map();
let timerId = 0;
let fetchCount = 0;
const context = {
    window: { __chatConfig: { mercureUrl: '/.well-known/mercure', currentUserId: 11 }, location: { href: 'https://portal.test/chat' } },
    document: { readyState: 'loading', addEventListener() {} },
    URL,
    fetch: async (url, options) => {
        assert.equal(url, '/api/chat/subscription');
        assert.equal(options.credentials, 'same-origin');
        fetchCount++;
        return { ok: true, json: async () => ({ topic: '/chat/user/11' }) };
    },
    EventSource: class {
        constructor(url, options) {
            this.url = url;
            this.options = options;
            sources.push(this);
        }
        close() { this.closed = true; }
    },
    setTimeout(callback, delay) { timers.set(++timerId, { callback, delay }); return timerId; },
    clearTimeout(id) { timers.delete(id); },
};
const file = path.join(__dirname, '../../public/front_end/assets/static/js/chat.js');
const source = fs.readFileSync(file, 'utf8');
vm.runInNewContext(source.replace(/\}\)\(\);\s*$/, 'window.subscribeForTest = subscribeUserTopic; })();'), context);

async function settle() { await new Promise(resolve => setImmediate(resolve)); }
async function runTimer(delay) {
    const entry = [...timers].find(([, timer]) => timer.delay === delay);
    assert.ok(entry, `Missing timer: ${delay}`);
    timers.delete(entry[0]);
    entry[1].callback();
    await settle();
}

(async () => {
    context.window.subscribeForTest();
    await settle();
    assert.equal(sources[0].url.searchParams.get('topic'), '/chat/user/11');
    assert.equal(sources[0].url.searchParams.has('lastEventID'), false);
    assert.equal(sources[0].options.withCredentials, true);

    sources[0].onmessage({ lastEventId: 'urn:uuid:first+event/1', data: '{"type":"ignored","room_id":99}' });
    sources[0].onerror();
    await runTimer(5000);
    assert.equal(sources[0].closed, true);
    assert.equal(sources[1].url.searchParams.get('lastEventID'), 'urn:uuid:first+event/1');

    sources[1].onmessage({ lastEventId: 'urn:uuid:latest-event', data: '{"type":"ignored","room_id":99}' });
    await runTimer(50 * 60 * 1000);
    assert.equal(sources[1].closed, true);
    assert.equal(sources[2].url.searchParams.get('lastEventID'), 'urn:uuid:latest-event');
    assert.equal(fetchCount, 3);
    console.log('PASS: initial subscription, reconnect cursor, JWT refresh cursor (3 subscriptions).');
})().catch(error => { console.error(error); process.exitCode = 1; });
