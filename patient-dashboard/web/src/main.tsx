/**
 * The starting point of the web page. The browser runs this file first: it loads the page styling,
 * finds the empty box in index.html that the dashboard is drawn into (the element with id "root"),
 * and draws the whole application (App, in app/App.tsx) inside it. Nothing comes in and nothing is
 * returned; everything the user sees grows from here.
 */
// OpenEMR's own Bootstrap version, so the cards' classes (bg-warning, badges, tables) render as on the old dashboard.
import 'bootstrap/dist/css/bootstrap.min.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './app/App';

// Find the empty placeholder in index.html. If it is missing the page cannot work, so stop with a clear error.
const container = document.getElementById('root');
if (container === null) {
    throw new Error('Missing #root element in index.html');
}

// Draw the application into the placeholder. The angle-bracket markup below is JSX: HTML-like text
// that describes what to draw. StrictMode is a development safety net that warns about risky code;
// it draws nothing of its own.
createRoot(container).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
