import { isAnAvailableType } from './is-an-available-type';

// eslint-disable-next-line @atlaskit/volt-strict-mode/no-multiple-exports
export function getAvailableTypes(transfer) {
  return Array.from(transfer.types).filter(function (type) {
    return isAnAvailableType({
      type: type,
      value: transfer.getData(type)
    });
  });
}