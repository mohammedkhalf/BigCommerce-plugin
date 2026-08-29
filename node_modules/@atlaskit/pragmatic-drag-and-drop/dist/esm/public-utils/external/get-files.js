/** Obtain an array of the dragged `File`s */
// eslint-disable-next-line @atlaskit/volt-strict-mode/no-multiple-exports
export function getFiles(_ref) {
  var source = _ref.source;
  return source.items
  // unlike other media types, for files:
  // item.kind is 'file'
  // item.type is the type of file eg 'image/jpg'
  // for other media types, item.type is the mime format
  .filter(function (item) {
    return item.kind === 'file';
  }).map(function (item) {
    return item.getAsFile();
  }).filter(function (file) {
    return file != null;
  });
}