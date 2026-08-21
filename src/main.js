import './app.css';
import { mount } from 'svelte';
import App from './App.svelte';
import { theme } from './lib/theme.js';
import { debug } from './lib/debug.js';

// Initialize theme from localStorage
theme.init();

// Initialize debug mode from localStorage  
const isDebug = localStorage.getItem('debug') === 'true';
if (isDebug) {
  debug.enable();
}

// Mount the Svelte app using Svelte 5 mount API
const app = mount(App, {
  target: document.getElementById('app')
});

export default app;
