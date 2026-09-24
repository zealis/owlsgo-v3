#!/usr/bin/env bash
# v3 → phpstudy 运行副本同步 + md5 复核
# 用法：bash "D:/Project files/owlsgo/v3/.tools/sync-www.sh"
set -u
export PATH="/c/Users/zeali/.workbuddy/binaries/PortableGit/versions/1.2.0/usr/bin:/c/Windows/System32:/usr/bin:/bin:$PATH"
SRC="D:\\Project files\\owlsgo\\v3"
DST="D:\\Program Files (x86)\\phpstudy_pro\\WWW\\v3"

robocopy "$SRC" "$DST" /MIR /XD data upload avatars preview /NFL /NDL /NJH > /dev/null
RC=$?
if [ "$RC" -gt 7 ]; then echo "robocopy 失败 exit=$RC"; exit "$RC"; fi

FAIL=0; N=0
DST_HOST="/d/Program Files (x86)/phpstudy_pro/WWW/v3"
cd "/d/Project files/owlsgo/v3" || exit 1
for f in $(git ls-files); do
  A=$(md5sum "$f" 2>/dev/null | cut -d' ' -f1)
  B=$(md5sum "$DST_HOST/$f" 2>/dev/null | cut -d' ' -f1)
  if [ "$A" != "$B" ]; then echo "DIFF: $f"; FAIL=1; fi
  N=$((N+1))
done
echo "核对 $N 个跟踪文件"
if [ "$FAIL" -eq 0 ]; then echo "SYNC OK：全部一致"; else echo "SYNC FAIL：存在差异，见上"; exit 1; fi
