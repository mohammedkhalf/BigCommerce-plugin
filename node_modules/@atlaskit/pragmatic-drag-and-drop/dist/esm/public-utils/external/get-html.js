import { HTMLMediaType } from '../../util/media-types/html-media-type';
// eslint-disable-next-line @atlaskit/volt-strict-mode/no-multiple-exports
export function getHTML(_ref) {
  var source = _ref.source;
  return source.getStringData(HTMLMediaType);
}