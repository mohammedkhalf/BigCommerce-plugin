import { textMediaType } from '../../util/media-types/text-media-type';
export function containsText(_ref) {
  var source = _ref.source;
  return source.types.includes(textMediaType);
}