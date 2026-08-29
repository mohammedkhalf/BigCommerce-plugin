import { HTMLMediaType } from '../../util/media-types/html-media-type';
export function containsHTML(_ref) {
  var source = _ref.source;
  return source.types.includes(HTMLMediaType);
}