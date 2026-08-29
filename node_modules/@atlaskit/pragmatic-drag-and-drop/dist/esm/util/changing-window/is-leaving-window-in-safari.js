import { isSafari } from '../is-safari';
import { symbols } from './count-events-for-safari';

// eslint-disable-next-line @atlaskit/volt-strict-mode/no-multiple-exports
export function isLeavingWindowInSafari(_ref) {
  var dragLeave = _ref.dragLeave;
  if (!isSafari()) {
    return false;
  }
  return dragLeave.hasOwnProperty(symbols.isLeavingWindow);
}