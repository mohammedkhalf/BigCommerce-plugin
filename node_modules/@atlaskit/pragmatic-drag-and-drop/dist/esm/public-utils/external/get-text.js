import { textMediaType } from '../../util/media-types/text-media-type';
/* Get the plain text that a user is dragging */
// eslint-disable-next-line @atlaskit/volt-strict-mode/no-multiple-exports
export function getText(_ref) {
  var source = _ref.source;
  return source.getStringData(textMediaType);
}