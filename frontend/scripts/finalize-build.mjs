import { rename } from 'node:fs/promises';
// Keep Laravel's public/index.php and publish the SPA under its expected name.
await rename(new URL('../../public/index.html', import.meta.url), new URL('../../public/app.html', import.meta.url));
