import { URLMediaType } from '../../util/media-types/url-media-type';
import { firefoxURLType } from './url';
export function containsURLs(_ref) {
  var source = _ref.source;
  return source.types.includes(URLMediaType) || source.types.includes(firefoxURLType);
}