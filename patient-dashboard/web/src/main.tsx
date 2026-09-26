// OpenEMR's own Bootstrap version, so the cards' classes (bg-warning, badges, tables) render as on the old dashboard.
import 'bootstrap/dist/css/bootstrap.min.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './app/App';

const container = document.getElementById('root');
if (container === null) {
    throw new Error('Missing #root element in index.html');
}

createRoot(container).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
