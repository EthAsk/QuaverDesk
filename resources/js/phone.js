// Entry for the phone's own window (/phone, also the installed app).
import { boot } from './phone/runtime';

const root = document.getElementById('phone');
if (root) boot(root);
