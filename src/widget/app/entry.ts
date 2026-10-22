/**
 * Chat app script (chatlume-app.js), loaded by the launcher on first open.
 *
 * A separate entry instead of a webpack chunk keeps webpack's chunk-loading
 * runtime out of the launcher; the launcher adds this file with a plain
 * script tag and then calls `window.ChatlumeApp.mount()`.
 */
import { mount } from './mount';

window.ChatlumeApp = { mount };
