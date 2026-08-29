import { isSafari } from '../is-safari';
import { symbols } from './count-events-for-safari';
export function isEnteringWindowInSafari(_ref) {
  var dragEnter = _ref.dragEnter;
  if (!isSafari()) {
    return false;
  }
  return dragEnter.hasOwnProperty(symbols.isEnteringWindow);
}