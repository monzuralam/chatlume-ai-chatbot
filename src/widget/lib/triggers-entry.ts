/**
 * Invitation script (chatlume-triggers.js), loaded by the launcher only on
 * pages with a proactive invitation.
 */
import { recordInvitation, watchTriggers } from './triggers';

window.ChatlumeTriggers = { watchTriggers, recordInvitation };
