#!/usr/bin/env bash
# Rebuild contract/latest from the checked-out main with the moved pins, and push it.
#
#   ci/drift-branch.sh "<commit title>" <path>...
#
# With OPEN_PR set (the drift pull request is open), commits that someone else pushed onto the
# branch (a scenario for the new minor, say) are carried onto the rebuilt branch, in order, and
# the push is a lease on the branch as it was read: nothing pushed meanwhile is overwritten. A
# carried commit that no longer applies stops the run with the commit named; the branch is left
# as it was. The drift's own commits are recognised by their author address and not carried:
# the new commit replaces them.
set -euo pipefail

branch="contract/latest"
bot="tribunedesk-contract-drift@users.noreply.github.com"
title="$1"
shift

git config user.name "tribunedesk-contract-drift"
git config user.email "$bot"

old=""
if [ -n "${OPEN_PR:-}" ] && git fetch --quiet origin "$branch" 2>/dev/null; then
  old="$(git rev-parse FETCH_HEAD)"
fi

git checkout -q -B "$branch"
git add -A "$@"
git commit -q -m "$title"

if [ -z "$old" ]; then
  git push --quiet --force origin "$branch"
  exit 0
fi

base="$(git merge-base "$old" HEAD)"
for commit in $(git rev-list --reverse --no-merges "$base..$old"); do
  if [ "$(git log -1 --format=%ae "$commit")" = "$bot" ]; then
    continue
  fi
  if ! git cherry-pick -x "$commit" >/dev/null 2>&1; then
    git cherry-pick --abort
    echo "::error::commit $(git log -1 --format='%h %s' "$commit") on $branch does not apply on the new pins; carry it by hand"
    exit 1
  fi
  echo "carried $(git log -1 --format='%h %s' "$commit")"
done
git push --quiet --force-with-lease="$branch:$old" origin "$branch"
