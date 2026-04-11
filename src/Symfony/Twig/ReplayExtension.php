<?php

namespace ErrorWatch\Symfony\Twig;

use ErrorWatch\Symfony\Service\SessionReplayManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for injecting rrweb session replay script.
 */
class ReplayExtension extends AbstractExtension
{
    // rrweb 1.x stable - UMD bundle compatible with <script> tags (unpkg CDN)
    private const RRWEB_CDN_URL = 'https://unpkg.com/rrweb@1.1.3/dist/rrweb.min.js';
    private const PAKO_CDN_URL = 'https://unpkg.com/pako@2.1.0/dist/pako.min.js';

    private SessionReplayManager $replayManager;

    public function __construct(SessionReplayManager $replayManager)
    {
        $this->replayManager = $replayManager;
    }

    /**
     * @return array<int, TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('error_watch_replay_script', [$this, 'getReplayScript'], ['is_safe' => ['html']]),
            new TwigFunction('error_watch_session_id', [$this, 'getSessionId']),
        ];
    }

    /**
     * Returns the HTML script tags for rrweb session replay
     * Include this in your base template before </body>.
     */
    public function getReplayScript(): string
    {
        if (!$this->replayManager->isEnabled()) {
            return '';
        }

        $config = $this->replayManager->getJsConfig();

        if (null === $config['sessionId']) {
            return '';
        }

        $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $rrwebUrl = self::RRWEB_CDN_URL;
        $pakoUrl = self::PAKO_CDN_URL;

        return <<<HTML
            <!-- ErrorWatch rrweb Session Replay (Error-Triggered) -->
            <script src="{$pakoUrl}"></script>
            <script src="{$rrwebUrl}"></script>
            <script>
            (function() {
              'use strict';

              var config = {$configJson};
              if (!config.enabled || !config.sessionId) return;

              // === Debug logging (silent by default) ===
              var DEBUG = config.debug === true;
              function _log() { if (DEBUG) console.log.apply(console, ['[ErrorWatch]'].concat(Array.prototype.slice.call(arguments))); }
              function _warn() { if (DEBUG) console.warn.apply(console, ['[ErrorWatch]'].concat(Array.prototype.slice.call(arguments))); }
              function _error() { console.error.apply(console, ['[ErrorWatch]'].concat(Array.prototype.slice.call(arguments))); }

              // === Circular Buffer Configuration ===
              var events = [];
              var maxBufferTime = 60000; // Keep last 60 seconds
              var lastFlushTime = 0;     // Debounce: min 2 seconds between flushes
              var stopFn = null;
              var pendingFlush = null;   // Delayed flush timer
              var POST_ERROR_DELAY = 2000; // Continue recording 2s after error (reduced from 10s to minimize race with backend errors)

              // === Compression ===
              function compressEvents(eventsArray) {
                try {
                  var jsonStr = JSON.stringify(eventsArray);
                  var compressed = pako.gzip(jsonStr);
                  var binary = '';
                  for (var i = 0; i < compressed.length; i++) {
                    binary += String.fromCharCode(compressed[i]);
                  }
                  return btoa(binary);
                } catch (e) {
                  _error('Compression failed:', e);
                  return btoa(JSON.stringify(eventsArray));
                }
              }

              // === Circular Buffer - Prune old events ===
              function pruneBuffer() {
                var cutoff = Date.now() - maxBufferTime;
                events = events.filter(function(e) { return e.timestamp > cutoff; });
              }

              // === Flush on Error ONLY (with post-error delay) ===
              function flushOnError(errorContext) {
                _log('flushOnError called:', errorContext.message, '| Buffer size:', events.length);

                // If a flush is already pending, skip (we'll capture this error in the delayed flush)
                if (pendingFlush) {
                  _log('Flush already scheduled, skipping');
                  return;
                }

                // Debounce: prevent rapid repeated flushes (min 5 seconds apart)
                var now = Date.now();
                if (now - lastFlushTime < 5000) {
                  _log('Flush debounced (too soon after last flush, wait', (5000 - (now - lastFlushTime)), 'ms)');
                  return;
                }

                // Schedule flush after delay to capture post-error events
                _log('Scheduling flush in', POST_ERROR_DELAY, 'ms to capture post-error events');

                pendingFlush = setTimeout(function() {
                  pendingFlush = null;
                  lastFlushTime = Date.now();

                  pruneBuffer();
                  if (events.length === 0) {
                    _log('No events to flush (buffer empty after prune)');
                    return;
                  }

                  var compressed = compressEvents(events);
                  _log('Flushing', events.length, 'events on error:', errorContext.message);

                  // Build error object without null values (Zod rejects null for optional fields)
                  var errorObj = { message: errorContext.message || 'Unknown error' };
                  if (errorContext.file) errorObj.file = errorContext.file;
                  if (typeof errorContext.line === 'number' && errorContext.line > 0) errorObj.line = errorContext.line;
                  if (errorContext.stack) errorObj.stack = errorContext.stack;
                  errorObj.level = errorContext.level || 'error';

                  fetch(config.endpoint + '/api/v1/replay/error', {
                    method: 'POST',
                    headers: {
                      'Content-Type': 'application/json',
                      'X-API-Key': config.apiKey
                    },
                    body: JSON.stringify({
                      sessionId: config.sessionId,
                      events: compressed,
                      error: errorObj,
                      url: window.location.href,
                      userAgent: navigator.userAgent,
                      timestamp: Date.now(),
                      release: config.release || null
                    })
                  })
                  .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    _log('Error + replay sent successfully');
                    return r.json();
                  })
                  .catch(function(e) {
                    _error('Failed to send error replay:', e);
                    lastFlushTime = 0; // Allow immediate retry on failure
                  });

                  events = []; // Clear buffer after flush
                }, POST_ERROR_DELAY);
              }

              // === Initialize Recording ===
              function initRecording() {
                _log('initRecording called, rrweb:', typeof rrweb, 'rrweb.record:', typeof rrweb?.record);

                if (typeof rrweb === 'undefined' || typeof rrweb.record !== 'function') {
                  _error('rrweb not loaded');
                  return;
                }

                _log('Recording initialized (error-triggered mode), sessionId:', config.sessionId);

                stopFn = rrweb.record({
                  emit: function(event) {
                    events.push(event);
                    if (events.length <= 3) {
                      _log('Event recorded, type:', event.type, '| Total:', events.length);
                    }

                    // Periodic prune to keep memory bounded
                    if (events.length > 1000) {
                      pruneBuffer();
                    }
                  },
                  // Privacy settings
                  maskAllInputs: true,
                  maskTextClass: 'rr-mask',
                  blockClass: 'rr-block',
                  maskInputOptions: {
                    password: true,
                    email: false,
                    tel: false
                  },
                  // Checkpoint every 30 seconds for seeking within buffer
                  checkoutEveryNms: 30000,
                  // Sampling to reduce data volume
                  sampling: {
                    mousemove: true,
                    mouseInteraction: true,
                    scroll: 150,
                    input: 'last'
                  },
                  inlineStylesheet: true,
                  recordCanvas: false,
                  collectFonts: false
                });
              }

              // === Error Detection ===

              // JavaScript errors
              var originalOnerror = window.onerror;
              window.onerror = function(message, source, lineno, colno, error) {
                flushOnError({
                  message: String(message),
                  file: source,
                  line: lineno,
                  stack: error ? error.stack : null,
                  level: 'error'
                });
                if (originalOnerror) return originalOnerror.apply(this, arguments);
              };

              // Unhandled promise rejections
              window.addEventListener('unhandledrejection', function(event) {
                var reason = event.reason;
                flushOnError({
                  message: reason && reason.message ? reason.message : 'Unhandled Promise Rejection',
                  stack: reason && reason.stack ? reason.stack : null,
                  level: 'error'
                });
              });

              // Intercept fetch to:
              // 1. Add X-Session-ID header so backend can link errors to replay
              // 2. Capture HTTP 5xx errors and send replay
              var originalFetch = window.fetch;
              window.fetch = function(input, init) {
                var url = typeof input === 'string' ? input : (input.url || '');

                // Inject session ID header for backend error linking
                init = init || {};
                init.headers = init.headers || {};
                if (typeof init.headers.set === 'function') {
                  init.headers.set('X-Session-ID', config.sessionId);
                } else {
                  init.headers['X-Session-ID'] = config.sessionId;
                }

                return originalFetch.call(this, input, init)
                  .then(function(response) {
                    // Capture HTTP 5xx errors (server errors) with replay
                    if (response.status >= 500) {
                      flushOnError({
                        message: 'HTTP ' + response.status + ' ' + response.statusText + ' - ' + url,
                        file: url,
                        level: 'error'
                      });
                    }
                    return response;
                  })
                  .catch(function(error) {
                    // Capture true network failures (CORS, offline, etc.)
                    flushOnError({
                      message: 'Network Error: ' + error.message,
                      file: url,
                      stack: error.stack,
                      level: 'error'
                    });
                    throw error;
                  });
              };

              // === Initialize (wait for rrweb to load) ===
              function waitForRrweb(callback, maxAttempts) {
                maxAttempts = maxAttempts || 50;
                var attempts = 0;
                var check = function() {
                  attempts++;
                  if (typeof rrweb !== 'undefined' && typeof rrweb.record === 'function') {
                    callback();
                  } else if (attempts < maxAttempts) {
                    setTimeout(check, 100);
                  } else {
                    _error('rrweb failed to load after', attempts, 'attempts');
                  }
                };
                check();
              }

              if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                  waitForRrweb(initRecording);
                });
              } else {
                waitForRrweb(initRecording);
              }

              // === Public API ===
              window.ErrorWatchReplay = {
                stop: function() { if (stopFn) stopFn(); },
                flush: function(errorContext) { flushOnError(errorContext || { message: 'Manual flush' }); },
                getSessionId: function() { return config.sessionId; },
                getEventCount: function() { return events.length; }
              };
            })();
            </script>
            HTML;
    }

    /**
     * Returns the current session ID (useful for debugging).
     */
    public function getSessionId(): ?string
    {
        return $this->replayManager->getSessionId();
    }
}
